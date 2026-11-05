<?php
// src/Source/VCenter/Client/Soap/AbstractSoapClient.php
//
// Zajednicka masinerija za rad sa vSphere Web Services API-jem (vim25) preko
// /sdk krajnje tacke: sesija, citanje osobina i raspakivanje XML-a.
//
// Nasledjuju je dva klijenta:
//   SoapHardwareClient — samo hardver hostova, kao dopuna REST klijentu
//   Vim25Client        — pun klijent, kada je ceo dijalekt SOAP
//
// Postupak je kod oba isti:
//   1. RetrieveServiceContent -> rootFolder, propertyCollector, verzija API-ja
//   2. Login                  -> kolacic sesije
//   3. CreateContainerView    -> pogled na sve objekte jednog tipa
//   4. RetrievePropertiesEx   -> trazena polja za SVE objekte odjednom,
//                                uz ContinueRetrievePropertiesEx dok ima tokena
//   5. DestroyView
//   6. Logout
//
// Zasto ContainerView a ne klasican TraversalSpec: isti rezultat sa deset puta
// manje XML-a. Klasican pristup trazi lanac specova kroz Datacenter ->
// hostFolder -> ComputeResource -> host, svaki sa svojim imenom i vezama.
//
// Zasto se XML cita preko local-name(): odgovor vCentera koristi vise
// prostora imena i prefiksi se razlikuju medju verzijama. Poredjenje po
// lokalnom imenu je otpornije od registrovanja prefiksa.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

use DOMDocument;
use DOMNode;
use DOMXPath;
use SensitiveParameter;
use SyncOps\Source\VCenter\VCenterException;

abstract class AbstractSoapClient
{
    protected ?string $rootFolder = null;
    protected ?string $propertyCollector = null;
    protected ?string $sessionManager = null;
    protected ?string $viewManager = null;
    protected ?string $productVersion = null;

    public function __construct(
        protected readonly SoapTransport $http,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
    ) {
    }

    public function callCount(): int
    {
        return $this->http->callCount();
    }

    public function productVersion(): ?string
    {
        return $this->productVersion;
    }

    // -----------------------------------------------------------------
    // Sesija
    // -----------------------------------------------------------------

    public function login(): void
    {
        if ($this->http->hasSession()) {
            return;
        }

        $this->retrieveServiceContent();

        $this->http->call('Login', sprintf(
            '<Login xmlns="urn:vim25">'
            . '<_this type="SessionManager">%s</_this>'
            . '<userName>%s</userName>'
            . '<password>%s</password>'
            . '</Login>',
            $this->escape($this->sessionManager ?? 'SessionManager'),
            $this->escape($this->username),
            $this->escape($this->password)
        ));
    }

    public function logout(): void
    {
        if (!$this->http->hasSession()) {
            return;
        }

        try {
            $this->http->call('Logout', sprintf(
                '<Logout xmlns="urn:vim25"><_this type="SessionManager">%s</_this></Logout>',
                $this->escape($this->sessionManager ?? 'SessionManager')
            ));
        } catch (VCenterException) {
            // Neuspela odjava nije vredna prijavljivanja — sesija istekne sama.
        }

        $this->http->forgetSession();
    }

    protected function retrieveServiceContent(): void
    {
        $xml = $this->http->call(
            'RetrieveServiceContent',
            '<RetrieveServiceContent xmlns="urn:vim25">'
            . '<_this type="ServiceInstance">ServiceInstance</_this>'
            . '</RetrieveServiceContent>'
        );

        $xpath = $this->xpath($xml);

        $this->rootFolder        = $this->text($xpath, '//*[local-name()="rootFolder"]');
        $this->propertyCollector = $this->text($xpath, '//*[local-name()="propertyCollector"]');
        $this->sessionManager    = $this->text($xpath, '//*[local-name()="sessionManager"]');
        $this->viewManager       = $this->text($xpath, '//*[local-name()="viewManager"]');
        $this->productVersion    = $this->text($xpath, '//*[local-name()="about"]/*[local-name()="version"]');

        // SOAPAction mora da nosi verziju API-ja: bez nje vCenter za novije
        // metode javlja da ne postoje.
        $this->http->setApiVersion(
            $this->text($xpath, '//*[local-name()="about"]/*[local-name()="apiVersion"]')
        );

        if ($this->rootFolder === null || $this->propertyCollector === null) {
            throw new VCenterException(
                'SOAP odgovor ne sadrzi rootFolder ni propertyCollector — '
                . 'krajnja tacka /sdk mozda nije vCenter.'
            );
        }
    }

    // -----------------------------------------------------------------
    // Citanje osobina
    // -----------------------------------------------------------------

    /**
     * Procitaj zadata polja za sve objekte jednog tipa unutar kontejnera.
     *
     * Kontejner je podrazumevano koren inventara; kada se preda moref
     * klastera, datacentra ili hosta, pogled se suzava na njega — tako se
     * filtriranje radi na strani vCentera, bez preuzimanja celog inventara.
     *
     * Parsiranje se predaje kao funkcija da bi se obavilo dok DOM jos zivi:
     * cvorovi vrede samo dok postoji dokument iz kog su izvadjeni.
     *
     * @param list<string> $properties
     * @param callable(DOMXPath, DOMNode, string): mixed $parse
     * @return array<string, mixed> moref => rezultat funkcije $parse
     */
    protected function eachObject(
        string $viewType,
        array $properties,
        callable $parse,
        ?string $container = null,
        string $containerType = 'Folder',
    ): array {
        $this->login();

        $view = $this->createContainerView(
            $viewType,
            $container ?? (string) $this->rootFolder,
            $containerType
        );

        try {
            $results = [];
            $token   = null;

            do {
                $xml = $token === null
                    ? $this->retrieveProperties($view, $viewType, $properties)
                    : $this->continueRetrieve($token);

                $xpath = $this->xpath($xml);

                foreach ($xpath->query('//*[local-name()="objects"]') as $object) {
                    $moref = $this->text($xpath, './*[local-name()="obj"]', $object);

                    if ($moref === null) {
                        continue;
                    }

                    $parsed = $parse($xpath, $object, $moref);

                    if ($parsed !== null) {
                        $results[$moref] = $parsed;
                    }
                }

                // Veliki inventar stize u delovima; token kaze da ima jos.
                $token = $this->text($xpath, '//*[local-name()="returnval"]/*[local-name()="token"]');
            } while ($token !== null && $token !== '');

            return $results;
        } finally {
            $this->destroyView($view);
        }
    }

    /**
     * Polja jednog objekta iz <objects>, po imenu.
     *
     * @return array<string, DOMNode>
     */
    protected function propSets(DOMXPath $xpath, DOMNode $object): array
    {
        $props = [];

        foreach ($xpath->query('./*[local-name()="propSet"]', $object) as $propSet) {
            $name = $this->text($xpath, './*[local-name()="name"]', $propSet);

            if ($name !== null) {
                $props[$name] = $propSet;
            }
        }

        return $props;
    }

    private function createContainerView(string $viewType, string $container, string $containerType): string
    {
        $xml = $this->http->call('CreateContainerView', sprintf(
            '<CreateContainerView xmlns="urn:vim25">'
            . '<_this type="ViewManager">%s</_this>'
            . '<container type="%s">%s</container>'
            . '<type>%s</type>'
            . '<recursive>true</recursive>'
            . '</CreateContainerView>',
            $this->escape($this->viewManager ?? 'ViewManager'),
            $this->escape($containerType),
            $this->escape($container),
            $this->escape($viewType)
        ));

        $view = $this->text($this->xpath($xml), '//*[local-name()="returnval"]');

        if ($view === null) {
            throw new VCenterException('vCenter nije vratio pogled na objekte tipa ' . $viewType . '.');
        }

        return $view;
    }

    /**
     * @param list<string> $properties
     */
    private function retrieveProperties(string $view, string $viewType, array $properties): string
    {
        $pathSet = '';

        foreach ($properties as $property) {
            $pathSet .= '<pathSet>' . $this->escape($property) . '</pathSet>';
        }

        return $this->http->call('RetrievePropertiesEx', sprintf(
            '<RetrievePropertiesEx xmlns="urn:vim25">'
            . '<_this type="PropertyCollector">%s</_this>'
            . '<specSet>'
            . '<propSet><type>%s</type>%s</propSet>'
            . '<objectSet>'
            . '<obj type="ContainerView">%s</obj>'
            . '<skip>true</skip>'
            . '<selectSet xsi:type="TraversalSpec">'
            . '<name>viewToObject</name>'
            . '<type>ContainerView</type>'
            . '<path>view</path>'
            . '<skip>false</skip>'
            . '</selectSet>'
            . '</objectSet>'
            . '</specSet>'
            . '<options />'
            . '</RetrievePropertiesEx>',
            $this->escape((string) $this->propertyCollector),
            $this->escape($viewType),
            $pathSet,
            $this->escape($view)
        ));
    }

    private function continueRetrieve(string $token): string
    {
        return $this->http->call('ContinueRetrievePropertiesEx', sprintf(
            '<ContinueRetrievePropertiesEx xmlns="urn:vim25">'
            . '<_this type="PropertyCollector">%s</_this>'
            . '<token>%s</token>'
            . '</ContinueRetrievePropertiesEx>',
            $this->escape((string) $this->propertyCollector),
            $this->escape($token)
        ));
    }

    private function destroyView(string $view): void
    {
        try {
            $this->http->call('DestroyView', sprintf(
                '<DestroyView xmlns="urn:vim25"><_this type="ContainerView">%s</_this></DestroyView>',
                $this->escape($view)
            ));
        } catch (VCenterException) {
            // Pogled istekne sam sa sesijom; neuspeh ovde nije vredan buke.
        }
    }

    // -----------------------------------------------------------------
    // Sitni pomocnici
    // -----------------------------------------------------------------

    protected function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();

        // Greske u XML-u se hvataju ovde, ne ispisuju kao PHP upozorenja.
        $previous = libxml_use_internal_errors(true);
        $loaded   = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new VCenterException('SOAP odgovor nije ispravan XML.');
        }

        return new DOMXPath($document);
    }

    protected function text(DOMXPath $xpath, string $query, ?DOMNode $context = null): ?string
    {
        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim($nodes->item(0)?->textContent ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Vrednost jednog polja unutar <val> elementa propSet-a. Kada je $field
     * null, uzima se sam sadrzaj <val> (za proste vrednosti kao memorySize).
     */
    protected function val(DOMXPath $xpath, ?DOMNode $propSet, ?string $field): ?string
    {
        if ($propSet === null) {
            return null;
        }

        $query = $field === null
            ? './*[local-name()="val"]'
            : './*[local-name()="val"]/*[local-name()="' . $field . '"]';

        return $this->text($xpath, $query, $propSet);
    }

    protected function intVal(DOMXPath $xpath, ?DOMNode $propSet, ?string $field): ?int
    {
        $value = $this->val($xpath, $propSet, $field);

        return ($value === null || !is_numeric($value)) ? null : (int) $value;
    }

    /**
     * Logicka vrednost iz vim25: stize kao "true"/"false".
     */
    protected function boolVal(DOMXPath $xpath, ?DOMNode $propSet, ?string $field): ?bool
    {
        $value = $this->val($xpath, $propSet, $field);

        return $value === null ? null : ($value === 'true' || $value === '1');
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
