<?php
// src/Source/VCenter/Client/Soap/SoapHardwareClient.php
//
// Jedina svrha: hardverski podaci o ESXi hostovima, koje REST API ne daje —
// proizvodjac, model, serijski broj, CPU, memorija, ESXi build.
//
// Koristi se kao dopuna REST klijentu, kroz HardwareAwareClient. Kada je ceo
// dijalekt integracije "soap", ovaj klijent se ne koristi — tada Vim25Client
// cita hardver u istom prolazu u kom cita i ostalo.
//
// Pet SOAP poziva ukupno, bez obzira na broj hostova: ServiceContent, Login,
// CreateContainerView, RetrievePropertiesEx, DestroyView (+ Logout).
//
// Podaci se citaju JEDNOM i cuvaju u memoriji, pa getHardware() po hostu ne
// pravi nov poziv.

declare(strict_types=1);

namespace SyncOps\Source\VCenter\Client\Soap;

final class SoapHardwareClient extends AbstractSoapClient
{
    use HostHardwareParser;

    /** @var array<string, array<string, mixed>>|null moref => hardverski podaci */
    private ?array $cache = null;

    /**
     * Hardverski podaci jednog hosta, ili null ako ga vCenter ne prijavljuje
     * (najcesce zato sto je host odvojen, pa nema sta da javi).
     *
     * @return array<string, mixed>|null
     */
    public function getHardware(string $moref): ?array
    {
        return $this->all()[$moref] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            /** @var array<string, array<string, mixed>> $hosts */
            $hosts = $this->eachObject(
                'HostSystem',
                self::HARDWARE_PROPERTIES,
                function ($xpath, $object, string $moref): ?array {
                    $hardware = $this->parseHostHardware($xpath, $this->propSets($xpath, $object));

                    return $this->hasAnyHardware($hardware) ? $hardware : null;
                }
            );

            $this->cache = $hosts;
        }

        return $this->cache;
    }
}
