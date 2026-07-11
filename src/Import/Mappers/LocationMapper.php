<?php

declare(strict_types=1);

namespace NwsCad\Import\Mappers;

use NwsCad\Import\ValueCaster;
use PDO;
use SimpleXMLElement;

/**
 * Writes the `locations` row for a call (#49, extracted from AegisXmlParser).
 *
 * Write strategy: delete-then-insert. locations is logically 1-to-1 with calls
 * but the schema has no UNIQUE constraint on call_id, so a prior row is deleted
 * before inserting — otherwise reprocessing a call accumulates duplicate
 * location rows (which SELECT DISTINCT cannot collapse when columns differ).
 */
final class LocationMapper
{
    public function __construct(private PDO $db)
    {
    }

    public function map(SimpleXMLElement $xml, int $callId): void
    {
        if (!isset($xml->Location)) {
            return;
        }

        $deleteStmt = $this->db->prepare("DELETE FROM locations WHERE call_id = ?");
        $deleteStmt->execute([$callId]);

        $loc = $xml->Location;

        $data = [
            'call_id' => $callId,
            'full_address' => (string)$loc->FullAddress ?: null,
            'house_number' => (string)$loc->HouseNumber ?: null,
            'house_number_suffix' => (string)$loc->HouseNumberSuffix ?: null,
            'prefix_directional' => (string)$loc->PrefixDirectional ?: null,
            'prefix_type' => (string)$loc->PrefixType ?: null,
            'street_name' => (string)$loc->StreetName ?: null,
            'street_type' => (string)$loc->StreetType ?: null,
            'street_directional' => (string)$loc->StreetDirectional ?: null,
            'city' => (string)$loc->City ?: null,
            'state' => (string)$loc->State ?: null,
            'zip' => (string)$loc->Zip ?: null,
            'zip4' => (string)$loc->Zip4 ?: null,
            'venue' => (string)$loc->Venue ?: null,
            'latitude_y' => ValueCaster::toDecimal((string)$loc->LatitudeY),
            'longitude_x' => ValueCaster::toDecimal((string)$loc->LongitudeX),
            'common_name' => (string)$loc->CommonName ?: null,
            'police_beat' => (string)$loc->PoliceBeat ?: null,
            'ems_district' => (string)$loc->EmsDistrict ?: null,
            'fire_quadrant' => (string)$loc->FireQuadrant ?: null,
            'census_tract' => (string)$loc->CensusTract ?: null,
            'station_area' => (string)$loc->StationArea ?: null,
            'rural_grid' => (string)$loc->RuralGrid ?: null,
            'nearest_cross_streets' => (string)$loc->NearestCrossStreets ?: null,
            'additional_info' => (string)$loc->AdditionalInfo ?: null,
            'lat_lon_description' => (string)$loc->LatLonDescription ?: null,
            'qualifier' => (string)$loc->Qualifier ?: null,
            'custom_layer' => (string)$loc->CustomLayer ?: null,
            'police_ori' => (string)$loc->PoliceOri ?: null,
            'ems_ori' => (string)$loc->EmsOri ?: null,
            'fire_ori' => (string)$loc->FireOri ?: null,
            'x_street_name' => (string)$loc->XStreetName ?: null,
            'x_street_type' => (string)$loc->XStreetType ?: null,
            'x_prefix_directional' => (string)$loc->XPrefixDirectional ?: null,
            'x_street_directional' => (string)$loc->XStreetDirectional ?: null,
            'x_prefix_type' => (string)$loc->XPrefixType ?: null
        ];

        $sql = "INSERT INTO locations (
            call_id, full_address, house_number, house_number_suffix, prefix_directional,
            prefix_type, street_name, street_type, street_directional, city, state,
            zip, zip4, venue, latitude_y, longitude_x, common_name, police_beat,
            ems_district, fire_quadrant, census_tract, station_area, rural_grid,
            nearest_cross_streets, additional_info, lat_lon_description, qualifier,
            custom_layer, police_ori, ems_ori, fire_ori, x_street_name, x_street_type,
            x_prefix_directional, x_street_directional, x_prefix_type
        ) VALUES (
            :call_id, :full_address, :house_number, :house_number_suffix, :prefix_directional,
            :prefix_type, :street_name, :street_type, :street_directional, :city, :state,
            :zip, :zip4, :venue, :latitude_y, :longitude_x, :common_name, :police_beat,
            :ems_district, :fire_quadrant, :census_tract, :station_area, :rural_grid,
            :nearest_cross_streets, :additional_info, :lat_lon_description, :qualifier,
            :custom_layer, :police_ori, :ems_ori, :fire_ori, :x_street_name, :x_street_type,
            :x_prefix_directional, :x_street_directional, :x_prefix_type
        )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
    }
}
