<?php

/** The original catalog, used until personnel_directory_migration.sql creates the `offices` table. */
const APPOINTMENT_DEFAULT_OFFICES = [
    "IT" => "IT Department",
    "IS" => "IS Department",
    "CS" => "CS Department",
    "DEANS" => "Dean's Office",
    "TECH_SUPPORT" => "Tech Support",
];

/**
 * Every department in the directory, archived ones included, so historical records
 * keep their names. Use appointment_office_active_map() for anything a person chooses.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_map(): array
{
    return appointment_office_directory()["all"];
}

/**
 * Departments that accept new appointments and new Office Personnel.
 *
 * @return array<string, string> code => full name
 */
function appointment_office_active_map(): array
{
    return appointment_office_directory()["active"];
}

/**
 * Reads the department directory once per request (pass $refresh after changing it).
 *
 * @return array{all: array<string, string>, active: array<string, string>}
 */
function appointment_office_directory(bool $refresh = false): array
{
    static $directory = null;
    if ($directory !== null && !$refresh) {
        return $directory;
    }
    $conn = $GLOBALS["conn"] ?? null;
    if ($conn instanceof mysqli) {
        try {
            $result = $conn->query("SELECT code, name, is_active FROM offices ORDER BY sort_order ASC, name ASC");
            if ($result) {
                $directory = ["all" => [], "active" => []];
                while ($row = $result->fetch_assoc()) {
                    $directory["all"][(string) $row["code"]] = (string) $row["name"];
                    if ((int) $row["is_active"] === 1) {
                        $directory["active"][(string) $row["code"]] = (string) $row["name"];
                    }
                }
                return $directory;
            }
        } catch (mysqli_sql_exception $error) {
            // The offices table is not installed yet; keep the original catalog.
        }
    }
    $directory = ["all" => APPOINTMENT_DEFAULT_OFFICES, "active" => APPOINTMENT_DEFAULT_OFFICES];
    return $directory;
}

function appointment_office_label(string $code): string
{
    $map = appointment_office_map();
    if (isset($map[$code])) {
        return $map[$code];
    }

    // Preserve readable labels for historical records without allowing these legacy
    // codes on new appointments.
    $legacy = [
        "CCI" => "College of Computing and Informatics (legacy)",
        "COED" => "College of Education (legacy)",
        "CEA" => "College of Engineering and Architecture (legacy)",
        "CIT" => "College of Industrial Technology (legacy)",
        "CAS" => "College of Arts and Sciences (legacy)",
    ];
    return $legacy[$code] ?? $code;
}
