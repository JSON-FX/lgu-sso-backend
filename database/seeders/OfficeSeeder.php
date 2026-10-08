<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            ['name' => 'ABC Hall', 'abbreviation' => 'ABC', 'is_active' => true],
            ['name' => 'Bureau Of Fire Protection', 'abbreviation' => 'BFP', 'is_active' => true],
            ['name' => 'Bureau Of Internal Revenue', 'abbreviation' => 'BIR', 'is_active' => true],
            ['name' => 'Business Permits And Licensing Division', 'abbreviation' => 'BPLD', 'is_active' => true],
            ['name' => 'Bukidnon State University', 'abbreviation' => 'BUKSU', 'is_active' => true],
            ['name' => 'Commission On Audit', 'abbreviation' => 'COA', 'is_active' => true],
            ['name' => 'Commission On Elections', 'abbreviation' => 'COMELEC', 'is_active' => true],
            ['name' => 'Civil Service Commission Field Office', 'abbreviation' => 'CSC-FO', 'is_active' => true],
            ['name' => 'Department of Interior And Local Government', 'abbreviation' => 'DILG', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - General Services Division', 'abbreviation' => 'MMO-GSD', 'is_active' => true],
            ['name' => 'Human Resource Management Office', 'abbreviation' => 'HRMO', 'is_active' => true],
            ['name' => 'Kapit-Bisig Laban sa Kahirapan-Comprehensive and Integrated Delivery of Social Services', 'abbreviation' => 'KALAHI-CIDSS', 'is_active' => true],
            ['name' => 'Local School Board', 'abbreviation' => 'LSB', 'is_active' => true],
            ['name' => 'Local School Board - District I', 'abbreviation' => 'LSBDI', 'is_active' => true],
            ['name' => 'Local School Board - District II', 'abbreviation' => 'LSBDII', 'is_active' => true],
            ['name' => 'Local School Board - District III', 'abbreviation' => 'LSBDIII', 'is_active' => true],
            ['name' => 'Local School Board - District IV', 'abbreviation' => 'LSBDIV', 'is_active' => true],
            ['name' => 'Municipal Accounting Office', 'abbreviation' => 'MACCO', 'is_active' => true],
            ['name' => 'Municipal Accounting Office - Commission On Audit', 'abbreviation' => 'MACCO-COA', 'is_active' => true],
            ['name' => 'Municipal Agriculture Office', 'abbreviation' => 'MAGRO', 'is_active' => true],
            ['name' => 'Municipal Agriculture Office - Demo Farm', 'abbreviation' => 'MAGRO-DF', 'is_active' => true],
            ['name' => 'Municipal Administrator\'s Office - Property Supply Management Division', 'abbreviation' => 'MAO-PSMD', 'is_active' => true],
            ['name' => 'Municipal Administrator\'s Office - Management Information System Section', 'abbreviation' => 'MAO-MISS', 'is_active' => true],
            ['name' => 'Municipal Assessor\'s Office', 'abbreviation' => 'MASSO', 'is_active' => true],
            ['name' => 'Municipal Budget Office', 'abbreviation' => 'MBO', 'is_active' => true],
            ['name' => 'Municipal Civil Registrar Office', 'abbreviation' => 'MCRO', 'is_active' => true],
            ['name' => 'Municipal Enterprises Management Office - Market', 'abbreviation' => 'MEMO-MARKET', 'is_active' => true],
            ['name' => 'Municipal Enterprises Management Office - Cemetery', 'abbreviation' => 'MEMO-CEMETERY', 'is_active' => true],
            ['name' => 'Municipal Enterprises Management Office - Lowan Lowan', 'abbreviation' => 'MEMO-LOWAN', 'is_active' => true],
            ['name' => 'Municipal Enterprises Management Office', 'abbreviation' => 'MEMO', 'is_active' => true],
            ['name' => 'Municipal Enterprises Management Office - Slaughterhouse', 'abbreviation' => 'MEMO-SH', 'is_active' => true],
            ['name' => 'Municipal Environment And Natural Resources Office', 'abbreviation' => 'MENRO', 'is_active' => true],
            ['name' => 'Solid Waste Management Program Office', 'abbreviation' => 'MENRO-SWMPO', 'is_active' => true],
            ['name' => 'Materials Recovery Facility', 'abbreviation' => 'MENRO-MRF', 'is_active' => true],
            ['name' => 'Municipal Engineer\'s Office - Facilities Maintenance', 'abbreviation' => 'MEO-FM', 'is_active' => true],
            ['name' => 'Municipal Engineer\'s Office', 'abbreviation' => 'MEO-ENG', 'is_active' => true],
            ['name' => 'Isolation Unit', 'abbreviation' => 'MHO-IU', 'is_active' => true],
            ['name' => 'Municipal Health Office - MCC', 'abbreviation' => 'MHO-MCC', 'is_active' => true],
            ['name' => 'Municipal Health Office', 'abbreviation' => 'MHO', 'is_active' => true],
            ['name' => 'Nutrition Division', 'abbreviation' => 'MHO-NUTRITION', 'is_active' => true],
            ['name' => 'Population Development Division', 'abbreviation' => 'MHO-POPDEV', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Barangay Affairs', 'abbreviation' => 'MMO-BA', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Business Permits And Licenses Division', 'abbreviation' => 'MMO-BPLD', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Community Affairs', 'abbreviation' => 'MMO-CA', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - ECCCO', 'abbreviation' => 'MMO-ECCCO', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Heritage Hall', 'abbreviation' => 'MMO-HERITAGE', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - LCS', 'abbreviation' => 'MMO-LCS', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Local Economic Development and Investment Promotions Office', 'abbreviation' => 'MMO-LEDIPO', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Livelihood Division', 'abbreviation' => 'MMO-LIVELIHOOD', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Mayor\'s Action Center', 'abbreviation' => 'MMO-MAC', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office', 'abbreviation' => 'MMO', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Municipal Project Monitoring', 'abbreviation' => 'MMO-MPM', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Personal Staff', 'abbreviation' => 'MMO-PS', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Policy Development', 'abbreviation' => 'MMO-PD', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - PRDP', 'abbreviation' => 'MMO-PRDP', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Public Information Office', 'abbreviation' => 'MMO-PIO', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Purchasing Section', 'abbreviation' => 'MMO-PS-PURCH', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Quezon Technical Education Center', 'abbreviation' => 'MMO-QTEC', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Secretariat Section', 'abbreviation' => 'MMO-SS', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Security', 'abbreviation' => 'MMO-SEC', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Service Vehicle Pool', 'abbreviation' => 'MMO-SVP', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Shooting Range', 'abbreviation' => 'MMO-SR', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Tourism And Civil Affairs Section', 'abbreviation' => 'MMO-TCAS', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Youth Development Section', 'abbreviation' => 'MMO-YDS', 'is_active' => true],
            ['name' => 'Bids And Awards Committee', 'abbreviation' => 'MMO-BAC', 'is_active' => true],
            ['name' => 'Municipal Project Monitoring (standalone export entry)', 'abbreviation' => 'MMO-MPM-ALT', 'is_active' => true],
            ['name' => 'Municipal Mayor\'s Office - Public Affairs, Information And Assistance Division', 'abbreviation' => 'MMO-PAIAD', 'is_active' => true],
            ['name' => 'Public Employment Service Office', 'abbreviation' => 'MMO-PESO', 'is_active' => true],
            ['name' => 'Municipal Planning And Development Office', 'abbreviation' => 'MPDO', 'is_active' => true],
            ['name' => 'Civil Security Unit', 'abbreviation' => 'MPSO-CSU', 'is_active' => true],
            ['name' => 'Local Enforcement Section', 'abbreviation' => 'MPSO-LOCAL', 'is_active' => true],
            ['name' => 'Municipal Disaster Risk Reduction And Management Office', 'abbreviation' => 'MPSO-MDRRMO', 'is_active' => true],
            ['name' => 'Municipal Public Safety Office', 'abbreviation' => 'MPSO', 'is_active' => true],
            ['name' => 'Traffic Management Group', 'abbreviation' => 'MPSO-TMG', 'is_active' => true],
            ['name' => 'Municipal Social Welfare And Development Office', 'abbreviation' => 'MSWDO', 'is_active' => true],
            ['name' => 'Municipal Treasurer\'s Office', 'abbreviation' => 'MTO', 'is_active' => true],
            ['name' => 'Municipal Administrator\'s Office', 'abbreviation' => 'MAO-ADMIN', 'is_active' => true],
            ['name' => 'Municipal Public Safety Office (standalone export entry)', 'abbreviation' => 'MPSO-STANDALONE', 'is_active' => true],
            ['name' => 'Land Transportation Office', 'abbreviation' => 'NGA-LTO', 'is_active' => true],
            ['name' => 'Philippine Drug Enforcement Agency', 'abbreviation' => 'NGA-PDEA', 'is_active' => true],
            ['name' => 'Public Attorney\'s Office', 'abbreviation' => 'PAO', 'is_active' => true],
            ['name' => 'Post Office', 'abbreviation' => 'PHILPOST', 'is_active' => true],
            ['name' => 'Philippine National Police', 'abbreviation' => 'PNP', 'is_active' => true],
            ['name' => 'Provincial Prosecutor\'s Office', 'abbreviation' => 'PPO', 'is_active' => true],
            ['name' => 'Quezon Health Center Infirmary', 'abbreviation' => 'MHO-QHCI', 'is_active' => true],
            ['name' => 'Sangguniang Bayan\'s Office', 'abbreviation' => 'SBO', 'is_active' => true],
        ];

        foreach ($offices as $office) {
            // Try to find by abbreviation first (handles renamed offices),
            // then by name (handles offices with changed abbreviation)
            $existing = Office::where('abbreviation', $office['abbreviation'])->first()
                ?? Office::where('name', $office['name'])->first();

            if ($existing) {
                $existing->update($office);
            } else {
                Office::create($office);
            }
        }
    }
}
