<?php

/*
 * Real auto-parts brands sold in the US: OE suppliers, factory-parts lines and the
 * aftermarket names shoppers search for. Grouped only to keep the list readable; a brand that
 * makes several kinds of parts appears once (the seeder also de-duplicates by slug).
 */

return [
    // Factory (OEM) parts lines
    'ACDelco', 'Motorcraft', 'Mopar', 'GM Genuine Parts', 'Ford Genuine Parts', 'Toyota Genuine Parts',
    'Honda Genuine Parts', 'Nissan Genuine Parts', 'Hyundai Genuine Parts', 'Kia Genuine Parts',
    'Subaru Genuine Parts', 'Mazda Genuine Parts', 'Lexus Genuine Parts', 'Acura Genuine Parts',
    'Infiniti Genuine Parts', 'Mitsubishi Genuine Parts', 'Volkswagen Genuine Parts', 'Audi Genuine Parts',
    'BMW Genuine Parts', 'MINI Genuine Parts', 'Mercedes-Benz Genuine Parts', 'Porsche Genuine Parts',
    'Volvo Genuine Parts', 'Jaguar Land Rover Genuine Parts', 'Tesla Genuine Parts', 'Mobis',

    // OE suppliers
    'Bosch', 'Denso', 'Aisin', 'Valeo', 'Continental', 'ZF', 'TRW', 'Lemförder', 'Sachs', 'Boge', 'Mahle',
    'Behr', 'Hella', 'Delphi', 'Hitachi', 'Hitachi Astemo', 'Mitsubishi Electric', 'NGK', 'NTK', 'Koyo',
    'NSK', 'NTN', 'Nachi', 'Timken', 'SKF', 'FAG', 'INA', 'LuK', 'Schaeffler', 'Pierburg', 'Kolbenschmidt',
    'Elring', 'Victor Reinz', 'Corteco', 'Febi Bilstein', 'Meyle', 'Vaico', 'Vemo', 'Swag', 'Topran',
    'Mann-Filter', 'Hengst', 'Nissens', 'Beru', 'Bremi', 'Lesjöfors', 'Textar', 'Pagid', 'Jurid', 'ATE',
    'Zimmermann', 'Ferodo', 'Mintex', 'Akebono', 'Advics', 'Nissin', 'Tokico', 'Sumitomo', 'Exedy',
    'Mitsuboshi', 'Bando', 'Keihin', 'Mikuni', 'Aisan', 'Musashi', 'Mitsuba', 'Sankei 555', 'GMB',
    'Kashiyama', 'Koyorad', 'Yuasa', 'Varta', 'Clarios', 'Remy', 'Delco Remy', 'Standard Motor Products',
    'Sensata', 'Schrader', 'Huf', 'Continental VDO', 'Siemens VDO', 'Magneti Marelli', 'Marelli', 'Dana',
    'Spicer', 'GKN', 'Eaton', 'BorgWarner', 'Garrett', 'Holset', 'Cummins', 'Allison Transmission',
    'Tremec', 'Jatco', 'Modine', 'Sanden', 'Federal-Mogul', 'Fel-Pro', 'Champion', 'Wagner', 'Moog',
    'Monroe', 'Walker', 'Gates', 'Dayco', 'ContiTech',

    // Brakes
    'Brembo', 'Raybestos', 'Bendix', 'EBC Brakes', 'Hawk Performance', 'StopTech', 'Power Stop', 'Centric Parts',
    'Carlson', 'Wilwood', 'Baer Brakes', 'R1 Concepts', 'Russell Performance', 'Goodridge', 'Max Advanced Brakes',
    'Detroit Axle', 'Duralast', 'Carquest', 'Valucraft', 'NAPA', 'Cardone', 'Dorman', 
    'Callahan', 'Disc Brakes Australia (DBA)', 'Girodisc', 'Project Mu', 'Endless', 'Performance Friction',

    // Filters
    'K&N', 'Fram', 'WIX', 'Purolator', 'Mobil 1', 'Hastings', 'Baldwin', 'Luber-finer', 'Champion Labs',
    'Royal Purple', 'Spectre', 'Green Filter', 'aFe Power', 'Airaid', 'Injen', 'S&B Filters', 'Volant', 'AEM',
    'Amsoil', 'STP', 'Microgard', 'EcoGard', 'Premium Guard',

    // Ignition
    'Autolite', 'E3 Spark Plugs', 'Brisk', 'MSD Ignition', 'Accel', 'Taylor Cable', 'Granatelli Motor Sports',
    'Blue Streak', 'Walker Products', 'PerTronix', 'Crane Cams', 'Holley', 'Edelbrock',

    // Engine
    'Cometic', 'Sealed Power', 'Clevite', 'Melling', 'Cloyes', 'Comp Cams', 'Lunati', 'Eagle Specialty Products',
    'Scat', 'Manley', 'JE Pistons', 'Wiseco', 'ARP', 'Moroso', 'Canton Racing', 'Milodon', 'Enginetech',
    'DNJ Engine Components', 'ITM Engine Components', 'Payen', 'Ajusa', 'National Oil Seals', 'Westar',
    'Anchor Industries', 'DEA Products', 'Pioneer Automotive', 'Dura-Bond', 'King Engine Bearings', 'ACL Bearings',
    'Brian Crower', 'Supertech Performance', 'Ferrea', 'Skunk2 Racing', 'Blox Racing', 'Hondata', 'KTuner',
    'Hylomar', 'Rein Automotive', 'CRP Industries', 'URO Parts', 'Pro Parts Sweden', 'Beck/Arnley',
    'Crown Automotive', 'Omix-ADA', 'Edelmann', 'Sunsong', 'A-Premium', 'MAS Industries', 'WAI Global',
    'Unity Automotive', 'Evan Fischer', 'Garage-Pro', 'APDTY', 'Atlantic Automotive Engineering', 'US Motor Works',
    'Goodyear Engineered Products', 'Continental Elite',

    // Fuel system
    'Carter', 'Airtex', 'Spectra Premium', 'Walbro', 'TI Automotive', 'Aeromotive', 'DeatschWerks', 'Fuelab',
    'Quick Fuel Technology', 'Demon Carburetion', 'Injector Dynamics', 'FAST', 'Fuel Injector Clinic', 'Siemens Deka',
    'Alliant Power', 'Fass Fuel Systems', 'AirDog', 'Fleece Performance', 'Industrial Injection', 'S&S Diesel Motorsport',

    // Exhaust
    'MagnaFlow', 'Flowmaster', 'Borla', 'Bosal', 'Corsa Performance', 'MBRP', 'Gibson Performance', 'Banks Power',
    'AP Exhaust', 'Eastern Catalytic', 'Catco', 'Davico', 'Hooker Headers', 'BBK Performance', 'JBA Headers',
    'Kooks Headers', 'Pypes Performance Exhaust', 'Dynomax', 'Cherry Bomb', 'Thrush', 'Vibrant Performance',
    'AWE Tuning', 'Akrapovič', 'Milltek Sport', 'Remus', 'Invidia', 'Tomei', 'HKS', 'GReddy', 'Apexi',
    'Stainless Works', 'Doug Thorley', 'Solo Performance', 'Roush Performance', 'Diamond Eye',

    // Emission control & diesel
    'Sinister Diesel', 'Pacific Performance Engineering (PPE)', 'BD Diesel Performance', 'Merchant Automotive',
    'H&S Performance', 'EZ Lynk', 'Kryptonite Products', 'Carli Suspension', 'Cognito Motorsports',

    // Cooling & A/C
    'Mishimoto', 'CSF Radiators', 'Griffin Thermal Products', 'Be Cool', 'Flex-a-lite', 'Derale', 'Hayden Automotive',
    'Four Seasons', 'Stant', 'Motorad', 'TYC', 'OSC Automotive', 'UAC', 'Global Parts Distributors (GPD)',
    'Santech Industries', 'Interdynamics', 'Vintage Air', 'Old Air Products', 'Spal', 'Davies Craig',

    // Electrical & batteries
    'Interstate Batteries', 'Optima Batteries', 'Odyssey Battery', 'DieHard', 'Exide', 'EverStart', 'Deka',
    'XS Power', 'Shorai', 'Antigravity Batteries', 'Lifeline Batteries', 'NorthStar Battery', 'Wells Vehicle Electronics',
    'Bussmann', 'Littelfuse', 'Blue Sea Systems', 'Painless Performance', 'American Autowire', 'Holstein Parts',

    // Lighting
    'Philips', 'Sylvania', 'Osram', 'PIAA', 'KC HiLiTES', 'Rigid Industries', 'Baja Designs', 'Diode Dynamics',
    'Morimoto', 'Spyder Auto', 'Anzo USA', 'Depo', 'Eagle Eye', 'Recon', 'Oracle Lighting', 'Lasfit', 'Auxbeam',
    'Nilight', 'GE Lighting', 'Putco', 'Vision X', 'Lightforce', 'J.W. Speaker', 'Truck-Lite', 'Nokya',
    'XenonDepot', 'Sealight', 'Fahren', 'Beamtech', 'Winjet', 'AlphaRex', 

    // Suspension & steering
    'KYB', 'Bilstein', 'Gabriel', 'Fox Racing Shox', 'Rancho', 'Eibach', 'H&R Springs', 'KW Suspensions', 'Tein',
    'BC Racing', 'Koni', 'Skyjacker', 'Rough Country', 'ReadyLIFT', 'Pro Comp', 'Zone Offroad', 'BDS Suspension',
    'Fabtech', 'Icon Vehicle Dynamics', 'Old Man Emu', 'ARB', 'Air Lift', 'Firestone Industrial Products', 'Arnott',
    'Suncore', 'Strutmaster', 'Belltech', 'Hotchkis', 'Whiteline', 'Energy Suspension', 'Prothane', 'Mevotech',
    'Sensen', 'FCS Automotive', 'Teraflex', 'Synergy Manufacturing', 'McGaughys', 'Tuff Country', 'Superlift',
    'Daystar', 'SPC Performance', 'Specialty Products Company', 'Ingalls Engineering', 'Ohlins', 'Fortune Auto',
    'Godspeed Project', 'ST Suspensions', 'Ridetech', 'QA1', 'Viking Performance', 'Pedders', 'Steeda',
    'Maval', 'A1 Cardone', 'Sway-A-Way', 'Kelderman',

    // Drivetrain & transmission
    'GSP North America', 'TrakMotive', 'Surtrack', 'Motive Gear', 'Yukon Gear & Axle', 'ACT Clutches',
    'Centerforce', 'Clutch Masters', 'McLeod Racing', 'South Bend Clutch', 'Fidanza', 'Hays', 'Spec Clutch',
    'Competition Clutch', 'TCI Automotive', 'B&M Racing', 'Hurst', 'Lokar', 'Transtar Industries', 'Sonnax',
    'ATP Automotive', 'Hughes Performance', 'Richmond Gear', 'Warn Industries', 'Mile Marker', 'Raybestos Powertrain',
    'Auburn Gear', 'Torsen', 'Driveshaft Shop', 'Inland Empire Driveline', 'Adams Driveshaft',
    'Tom Woods', 'Revolution Gear & Axle', 'G2 Axle & Gear', 'Nitro Gear & Axle', 'Alloy USA', 'Ten Factory',

    // Wheels & tires
    'Michelin', 'Goodyear', 'Bridgestone', 'Firestone', 'Pirelli', 'Yokohama', 'Toyo Tires',
    'Cooper Tires', 'BFGoodrich', 'Hankook', 'Kumho', 'Falken', 'Nitto', 'General Tire', 'Nexen', 'Dunlop',
    'Hercules Tires', 'Uniroyal', 'Kelly Tires', 'Mickey Thompson', 'Maxxis', 'Vredestein',
    'Nokian Tyres', 'GT Radial', 'Ironman Tires', 'Federal Tires', 'Achilles', 'Atturo', 'Milestar', 'Laufenn',
    'Fuel Off-Road', 'Method Race Wheels', 'KMC Wheels', 'American Racing', 'Moto Metal', 'XD Series', 'Vision Wheel',
    'Ultra Wheel', 'Enkei', 'BBS', 'OZ Racing', 'Rays', 'Work Wheels', 'Konig', 'Motegi Racing', 'TSW Wheels',
    'Rotiform', 'Vossen', 'HRE Wheels', 'Forgestar', 'Weld Racing', 'Cragar', 'Raceline Wheels', 'Black Rhino',
    'Pro Comp Wheels', 'Mamba Wheels', 'Gorilla Automotive', 'McGard', 'Coyote Wheel Accessories', 'Spidertrax',
    'Titan7', 'Apex Race Parts', 'Sparco Wheels', 'Fifteen52', 'Level 8', 'Hostile Wheels',

    // Body, exterior & truck
    'Go Rhino', 'Westin', 'AMP Research', 'N-Fab', 'Lund', 'Bushwacker', 'WeatherTech', 'Husky Liners',
    'Auto Ventshade (AVS)', 'Rugged Liner', 'BAK Industries', 'TruXedo', 'Extang', 'UnderCover', 'Retrax',
    'Gator Covers', 'Smittybilt', 'Rugged Ridge', 'Bestop', 'Barricade', 'ARIES', 'Thule', 'Yakima', 'Rhino-Rack',
    'Front Runner', 'Fab Fours', 'Iron Cross', 'Ranch Hand', 'Road Armor', 'DV8 Offroad', 'Addictive Desert Designs',
    'Superwinch', 'Seibon Carbon', 'Anderson Composites', 'APR Performance', 'Rally Armor', 'Duraflex',
    'Kool Vue', 'CIPA', 'K-Source', 'Velvac', 'Metro Moulded Parts', 'Fairchild Industries', 'Steele Rubber Products',
    'SoffSeal', 'Pilkington', 'Fuyao', 'Sherman Parts', 'Keystone Automotive', 'LKQ', 'Covercraft', 'Coverking',
    'Budge', 'Leer', 'ARE Truck Caps', 'Decked', 'Bedrug', 'Line-X', 'Rhino Linings', 'Leitner Designs',
    'Roam Adventure Co.', 'Rival 4x4', 'Ford Performance', 'Chevrolet Performance',
    'Direct Connection', 'TRD (Toyota Racing Development)', 'NISMO', 'Mugen', 'Volk Racing', 'Cusco',
    'Tanabe', 'Megan Racing', 'Ark Performance', 'Stillen', 'Cobb Tuning', 'Vortech', 'ProCharger',

    // Wipers
    'Rain-X', 'Trico', 'ANCO', 

    // Interior
    'Lloyd Mats', 'Katzkin', 'Wet Okole', 'Clazzio', 'FH Group', 'Auto Meter', 'GlowShift', 'Grant Products',
    'NRG Innovations', 'Momo', 'Sparco', 'Recaro', 'Bride', 'OMP', 'Corbeau', 'Procar by Scat', 'Takata',
    'Schroth', 'Simpson', 'G-Force', 'TMI Products', 'Classic Industries', 'Year One', 'OER Parts',

    // Audio & electronics
    'Metra Electronics', 'Scosche', 'PAC Audio', 'iDatalink Maestro', 'Axxess', 'Pioneer', 'Kenwood', 'Alpine',
    'JVC', 'Sony', 'Rockford Fosgate', 'JL Audio', 'Kicker', 'Focal', 'Infinity', 'JBL', 'Polk Audio', 'MB Quart',
    'Hertz', 'Audison', 'Skar Audio', 'Sundown Audio', 'DS18', 'Memphis Car Audio', 'Boss Audio', 'Planet Audio',
    'Orion', 'Hifonics', 'Cerwin-Vega', 'Garmin', 'Cobra Electronics', 'Uniden', 'Escort Radar', 'Valentine One',
    'Viofo', 'Vantrue', 'Nextbase', 'Thinkware', 'BlackVue', 'Rexing', 'Compustar', 'Viper', 'Python', 'Clifford',
    'Avital', 'Code Alarm', 'Auto-Vox', 'Rear View Safety', 'Brandmotion', 'iOttie', 'ProClip', 'Stinger',
    'Sound Ordnance', 

    // Towing
    'CURT', 'Draw-Tite', 'Reese', 'B&W Trailer Hitches', 'Gen-Y Hitch', 'Tekonsha', 'Hopkins', 'Andersen Hitches',
    'Blue Ox', 'Roadmaster', 'Demco', 'PullRite', 'Husky Towing', 'Equal-i-zer', 'Fastway', 'Valley Industries',
    'Pollak', 'Hidden Hitch', 'Torklift', 'Bargman', 'Wesbar', 'Optronics', 'Brake Buddy', 'Redarc', 'Rhino USA',

    // Performance & tuning
    'Hypertech', 'Superchips', 'SCT Performance', 'Bully Dog', 'DiabloSport', 'Edge Products', 'HP Tuners', 'EcuTek',
    'Whipple', 'Magnuson', 'Roush', 'Paxton', 'Precision Turbo', 'Turbonetics', 'Nitrous Express', 'ZEX',
    'Nitrous Outlet', 'NOS', 'Tial', 'Turbosmart', 'GFB (Go Fast Bits)', 'Haltech', 'Innovate Motorsports',
    'Lingenfelter', 'Pedal Commander', 'Sprint Booster', 'Mr. Gasket', 'Aeroquip', "Earl's Performance Plumbing",
    'Fragola', 'Radium Engineering', 'Nameless Performance', 'Boomba Racing', 'CP-E', 'IAG Performance',
    'Killer B Motorsport', 'Perrin Performance', 'Grimmspeed', 'Integrated Engineering', '034Motorsport', 'Unitronic',
    'APR', 'Burger Motorsports', 'Dinan', 'Vorshlag', 'BMR Suspension', 'UMI Performance', 'Spohn Performance',
    'J&M Products', 'Speedmaster', 'Summit Racing', 'Jegs', 'Proform', 

    // Oils, fluids & chemicals
    'Castrol', 'Valvoline', 'Pennzoil', 'Shell Rotella', 'Quaker State', 'Liqui Moly', 'Motul', 'Red Line',
    'Lucas Oil', 'Prestone', 'Peak', 'Zerex', 'Sea Foam', 'Gumout', 'Chevron Techron', 'Lubegard', "Bar's Leaks",
    'BlueDEF', 'Permatex', 'Loctite', '3M', 'CRC Industries', 'WD-40', 'Sta-Bil', 'Marvel Mystery Oil', 'Kendall',
    'Havoline', 'Ravenol', 'Fuchs', 'TotalEnergies', 'Idemitsu', 'Torco', 'Driven Racing Oil', "Schaeffer's",
    'Mag 1', 'Shell', 'Mobil', "Wynn's", 'J-B Weld', 'Gunk', 'A/C Pro', 'Super Tech', 'Hot Shot\'s Secret',
    'Archoil', 'Opti-Lube', 'Power Service', 'Stanadyne', 'Pentosin', 

    // Car care
    "Meguiar's", 'Chemical Guys', 'Mothers', 'Turtle Wax', 'Armor All', "Griot's Garage", "Adam's Polishes",
    'Sonax', 'Gyeon', 'CarPro', 'Koch-Chemie', 'P&S Detail Products', "McKee's 37", 'Invisible Glass', 'Collinite',
    'Optimum Polymer Technologies', 'Rupes', 'Lake Country Manufacturing', 'The Rag Company', 'Little Trees',
    'California Car Duster', 'Dupli-Color', 'Rust-Oleum', 'VHT', 'POR-15', 'Eastwood', 'Plasti Dip', 'Bondo',
    'Ceramic Pro', 'Jescar', 'FLEX', 'Malco Automotive', 'Simoniz', 'Zymol',

    // Tools & equipment
    'Snap-on', 'Matco Tools', 'Mac Tools', 'Cornwell Tools', 'Craftsman', 'DeWalt', 'Milwaukee Tool', 'Makita',
    'Ingersoll Rand', 'Gearwrench', 'Tekton', 'Stanley', 'Lisle', 'OTC Tools', 'Astro Pneumatic', 'Sunex Tools',
    'Wright Tool', 'Proto', 'Capri Tools', 'Powerbuilt', 'Performance Tool', 'Titan Tools', 'OEMTOOLS', 'Autel',
    'Launch Tech', 'Innova Electronics', 'BlueDriver', 'Foxwell', 'Topdon', 'Ancel', 'Actron', 'Fluke', 'NOCO',
    'Schumacher Electric', 'Clore Automotive', 'Battery Tender', 'CTEK', 'Arcan', 'Hein-Werner', 'BendPak',
    'Viair', 'Slime', 'Wera', 'Knipex', 'Hazet', 'Facom', 'Bahco', 'Icon Tools', 'Pittsburgh Automotive',
    'Daytona Jacks', 'Ryobi', 'Klein Tools', 'Irwin', 'Channellock', 'Vise-Grip', 'Mityvac', 'Motive Products',
    'Steelman', 'Cal-Van Tools', 'Kastar', 'Assenmacher', 'Schley Products', 'Thexton',

    // Hybrid & EV
    'Greentec Auto', 'ChargePoint', 'Grizzl-E', 'Emporia Energy',
    'Wallbox', 'JuiceBox', 'Lectron', 'ClipperCreek',
];
