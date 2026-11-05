<?php
// src/Source/SourceTester.php
//
// Provera konekcije za jedan tip integracije.
//
// Postoji da bi GUI i CLI zvali istu stvar bez obzira na to da li iza
// integracije stoji vCenter ili AssetOPS. Sta se tacno proverava zna
// implementacija: vCenter broji datacentre i masine, AssetOPS CI-jeve i
// statuse. Zajednicko je samo da se proba povezivanje, prebroji ono sto se
// vidi i ishod zapise u integration.last_test_*.
//
// Implementacija NE SME da baci izuzetak: neuspeh je takodje ishod i vraca
// se kao TestResult::failed(). Provera konekcije koja obori stranicu ne bi
// bila provera nego jos jedan nacin da se ne sazna sta je problem.

declare(strict_types=1);

namespace SyncOps\Source;

use SyncOps\Integration\Integration;

interface SourceTester
{
    public function test(Integration $integration): TestResult;
}
