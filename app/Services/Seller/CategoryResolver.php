<?php

namespace App\Services\Seller;

use App\Models\Category;
use App\Services\AI\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Maps free-text category hints from an imported CSV (Shopify "Product Category",
 * a "Type" column, tags, or just the product title) onto Panmotive's fixed
 * category tree. Deterministic keyword + fuzzy matching first; an optional AI
 * pass cleans up whatever is left (batched — one call per import).
 */
class CategoryResolver
{
    /**
     * Words sellers and other platforms use that aren't our category names (lower-case). The longest
     * phrase found in a title wins, whether it's a keyword here or a category name, so
     * "wheel bearing" beats the shorter category name "Wheels". Every slug must exist in
     * database/seeders/data/categories.php (a test checks this).
     */
    private const KEYWORDS = [
        // brakes
        'brake pad' => 'brake-pads', 'disc pad' => 'brake-pads', 'pad set' => 'brake-pads', 'ceramic pad' => 'brake-pads',
        'brake rotor' => 'brake-rotors', 'brake disc' => 'brake-rotors', 'disc rotor' => 'brake-rotors', 'rotor' => 'brake-rotors',
        'brake caliper' => 'calipers', 'caliper' => 'calipers', 'caliper bracket' => 'caliper-brackets',
        'brake line' => 'brake-lines', 'brake hose' => 'brake-lines', 'stainless brake line' => 'brake-lines',
        'brake shoe' => 'brake-shoes', 'brake drum' => 'brake-drums', 'big brake' => 'big-brake-kits',
        'brake kit' => 'brake-kits', 'pads and rotors' => 'brake-kits', 'pad and rotor' => 'brake-kits',
        'brake hardware' => 'brake-hardware-kits', 'master cylinder' => 'master-cylinders', 'brake master' => 'master-cylinders',
        'brake booster' => 'brake-boosters', 'vacuum booster' => 'brake-boosters', 'wheel cylinder' => 'wheel-cylinders',
        'abs pump' => 'abs-modules-pumps', 'abs module' => 'abs-modules-pumps', 'abs control' => 'abs-modules-pumps',
        'parking brake' => 'parking-brake-parts', 'emergency brake' => 'parking-brake-parts', 'e-brake' => 'parking-brake-parts',
        'proportioning valve' => 'brake-proportioning-valves', 'brake wear sensor' => 'brake-wear-sensors', 'pad wear sensor' => 'brake-wear-sensors',
        'brake fluid' => 'brake-fluid', 'brake' => 'brakes',

        // engine
        'crate engine' => 'complete-engines', 'long block' => 'complete-engines', 'short block' => 'engine-blocks',
        'engine assembly' => 'complete-engines', 'cylinder head' => 'cylinder-heads', 'piston ring' => 'pistons-rings',
        'piston' => 'pistons-rings', 'crankshaft' => 'crankshafts', 'camshaft' => 'camshafts', 'cam shaft' => 'camshafts',
        'lifter' => 'lifters-rocker-arms', 'rocker arm' => 'lifters-rocker-arms', 'pushrod' => 'lifters-rocker-arms',
        'valve spring' => 'valves-valve-springs', 'intake valve' => 'valves-valve-springs', 'exhaust valve' => 'valves-valve-springs',
        'rod bearing' => 'engine-bearings', 'main bearing' => 'engine-bearings', 'cam bearing' => 'engine-bearings',
        'connecting rod' => 'connecting-rods', 'timing chain' => 'timing-kits-chains', 'timing kit' => 'timing-kits-chains',
        'timing cover' => 'timing-kits-chains', 'timing belt' => 'timing-belts', 'serpentine belt' => 'belts', 'drive belt' => 'belts',
        'v-belt' => 'belts', 'belt' => 'belts', 'belt tensioner' => 'pulleys-tensioners', 'idler pulley' => 'pulleys-tensioners',
        'tensioner' => 'pulleys-tensioners', 'pulley' => 'pulleys-tensioners', 'harmonic balancer' => 'harmonic-balancers',
        'crank pulley' => 'harmonic-balancers', 'head gasket' => 'head-gaskets', 'valve cover gasket' => 'gaskets',
        'oil pan gasket' => 'gaskets', 'intake gasket' => 'gaskets', 'gasket' => 'gaskets', 'oil seal' => 'gaskets', 'rear main seal' => 'gaskets',
        'motor mount' => 'engine-mounts', 'engine mount' => 'engine-mounts', 'oil pump' => 'oil-pumps', 'oil pan' => 'oil-pans',
        'oil cooler' => 'oil-coolers', 'valve cover' => 'valve-covers', 'rocker cover' => 'valve-covers',
        'intake manifold' => 'intake-manifolds', 'vvt solenoid' => 'variable-valve-timing-parts', 'cam phaser' => 'variable-valve-timing-parts',
        'variable valve timing' => 'variable-valve-timing-parts', 'turbocharger' => 'turbochargers', 'turbo' => 'turbochargers',
        'supercharger' => 'superchargers', 'rebuild kit' => 'engine-rebuild-kits', 'overhaul kit' => 'engine-rebuild-kits',
        'dipstick' => 'dipsticks-tubes', 'engine' => 'engine', 'valve' => 'engine',

        // ignition
        'spark plug' => 'spark-plugs', 'sparkplug' => 'spark-plugs', 'iridium plug' => 'spark-plugs',
        'ignition coil' => 'ignition-coils', 'coil pack' => 'ignition-coils', 'coil on plug' => 'ignition-coils',
        'plug wire' => 'spark-plug-wires', 'ignition wire' => 'spark-plug-wires', 'glow plug' => 'glow-plugs',
        'distributor' => 'distributors-parts', 'ignition switch' => 'ignition-switches', 'lock cylinder' => 'ignition-lock-cylinders',
        'ignition module' => 'ignition-control-modules', 'ignition control' => 'ignition-control-modules', 'ignition' => 'ignition',

        // fuel
        'fuel pump' => 'fuel-pumps', 'fuel injector' => 'fuel-injectors', 'injector' => 'fuel-injectors',
        'gas tank' => 'fuel-tanks', 'fuel tank' => 'fuel-tanks', 'fuel line' => 'fuel-lines-hoses', 'fuel hose' => 'fuel-lines-hoses',
        'fuel rail' => 'fuel-rails', 'fuel pressure regulator' => 'fuel-pressure-regulators', 'sending unit' => 'fuel-sending-units',
        'fuel level sensor' => 'fuel-sending-units', 'gas cap' => 'fuel-caps-filler-necks', 'fuel cap' => 'fuel-caps-filler-necks',
        'filler neck' => 'fuel-caps-filler-necks', 'throttle body' => 'throttle-bodies', 'carburetor' => 'carburetors',
        'carb' => 'carburetors', 'efi kit' => 'efi-conversion-kits', 'efi conversion' => 'efi-conversion-kits', 'fuel' => 'fuel-system',

        // air intake
        'cold air intake' => 'cold-air-intakes', 'air intake kit' => 'cold-air-intakes', 'short ram' => 'cold-air-intakes',
        'intake hose' => 'intake-hoses-tubes', 'intake tube' => 'intake-hoses-tubes', 'intake boot' => 'intake-hoses-tubes',
        'air box' => 'air-boxes', 'airbox' => 'air-boxes', 'intercooler pipe' => 'intercooler-piping', 'charge pipe' => 'intercooler-piping',
        'boost pipe' => 'intercooler-piping', 'intercooler' => 'intercoolers', 'throttle body spacer' => 'throttle-body-spacers',
        'air intake' => 'air-intake',

        // exhaust & emissions
        'muffler' => 'mufflers', 'catalytic converter' => 'catalytic-converters', 'cat converter' => 'catalytic-converters',
        'exhaust manifold' => 'exhaust-manifolds', 'header' => 'headers', 'cat-back' => 'cat-back-exhaust-systems',
        'catback' => 'cat-back-exhaust-systems', 'cat back' => 'cat-back-exhaust-systems', 'axle-back' => 'axle-back-exhaust-systems',
        'axle back' => 'axle-back-exhaust-systems', 'downpipe' => 'downpipes', 'down pipe' => 'downpipes', 'exhaust pipe' => 'exhaust-pipes',
        'tailpipe' => 'exhaust-pipes', 'y-pipe' => 'exhaust-pipes', 'x-pipe' => 'exhaust-pipes', 'flex pipe' => 'flex-pipes',
        'resonator' => 'resonators', 'exhaust tip' => 'exhaust-tips', 'exhaust gasket' => 'exhaust-gaskets', 'donut gasket' => 'exhaust-gaskets',
        'exhaust hanger' => 'exhaust-hangers-clamps', 'exhaust clamp' => 'exhaust-hangers-clamps', 'exhaust' => 'exhaust',
        'egr valve' => 'egr-valves-coolers', 'egr cooler' => 'egr-valves-coolers', 'egr' => 'egr-valves-coolers',
        'evap canister' => 'evap-canisters-purge-valves', 'purge valve' => 'evap-canisters-purge-valves', 'vent valve' => 'evap-canisters-purge-valves',
        'pcv valve' => 'pcv-valves', 'pcv' => 'pcv-valves', 'air pump' => 'secondary-air-injection', 'smog pump' => 'secondary-air-injection',
        'secondary air' => 'secondary-air-injection', 'dpf' => 'diesel-particulate-filters', 'particulate filter' => 'diesel-particulate-filters',
        'def pump' => 'def-scr-parts', 'def tank' => 'def-scr-parts', 'scr' => 'def-scr-parts', 'def heater' => 'def-scr-parts',

        // cooling & heating
        'radiator hose' => 'radiator-hoses', 'coolant hose' => 'radiator-hoses', 'radiator cap' => 'radiator-caps', 'radiator' => 'radiators',
        'water pump' => 'water-pumps', 'thermostat' => 'thermostats', 'cooling fan' => 'cooling-fans', 'radiator fan' => 'cooling-fans',
        'electric fan' => 'cooling-fans', 'fan clutch' => 'fan-clutches', 'coolant reservoir' => 'coolant-reservoirs',
        'expansion tank' => 'coolant-reservoirs', 'overflow tank' => 'coolant-reservoirs', 'water outlet' => 'coolant-pipes-outlets',
        'thermostat housing' => 'coolant-pipes-outlets', 'coolant pipe' => 'coolant-pipes-outlets', 'coolant' => 'coolant-antifreeze',
        'antifreeze' => 'coolant-antifreeze', 'cooling' => 'cooling',
        'ac compressor' => 'ac-compressors', 'a/c compressor' => 'ac-compressors', 'air conditioning compressor' => 'ac-compressors',
        'ac condenser' => 'ac-condensers', 'a/c condenser' => 'ac-condensers', 'condenser' => 'ac-condensers',
        'evaporator' => 'evaporators', 'heater core' => 'heater-cores', 'blower motor resistor' => 'blower-motor-resistors',
        'blower resistor' => 'blower-motor-resistors', 'blower motor' => 'blower-motors', 'ac hose' => 'ac-hoses-lines', 'a/c line' => 'ac-hoses-lines',
        'expansion valve' => 'expansion-valves-orifice-tubes', 'orifice tube' => 'expansion-valves-orifice-tubes',
        'accumulator' => 'accumulators-receiver-driers', 'receiver drier' => 'accumulators-receiver-driers', 'receiver dryer' => 'accumulators-receiver-driers',
        'heater valve' => 'heater-control-valves', 'climate control' => 'climate-control-modules', 'hvac control' => 'climate-control-modules',
        'refrigerant' => 'ac-refrigerant', 'r134a' => 'ac-refrigerant', 'r1234yf' => 'ac-refrigerant',

        // electrical
        'battery cable' => 'battery-cables-terminals', 'battery terminal' => 'battery-cables-terminals',
        'battery charger' => 'battery-chargers', 'battery tender' => 'battery-chargers', 'jump starter' => 'jump-starters',
        'jump pack' => 'jump-starters', 'battery' => 'batteries', 'alternator' => 'alternators', 'starter motor' => 'starters', 'starter' => 'starters',
        'fuse box' => 'fuses-fuse-boxes', 'fuse' => 'fuses-fuse-boxes', 'relay' => 'relays', 'switch' => 'switches',
        'wiring harness' => 'wiring-harnesses', 'wire harness' => 'wiring-harnesses', 'pigtail' => 'connectors-pigtails', 'connector' => 'connectors-pigtails',
        'voltage regulator' => 'voltage-regulators', 'ecu' => 'engine-control-modules-ecu', 'ecm' => 'engine-control-modules-ecu',
        'pcm' => 'engine-control-modules-ecu', 'engine computer' => 'engine-control-modules-ecu', 'bcm' => 'body-control-modules',
        'body control module' => 'body-control-modules', 'horn' => 'horns', 'window motor' => 'power-window-motors',
        'door lock actuator' => 'door-lock-actuators', 'lock actuator' => 'door-lock-actuators', 'instrument cluster' => 'instrument-clusters',
        'speedometer' => 'instrument-clusters', 'key fob' => 'key-fobs-remotes', 'remote key' => 'key-fobs-remotes',
        'keyless entry' => 'key-fobs-remotes', 'wiring' => 'electrical', 'electrical' => 'electrical',

        // sensors
        'oxygen sensor' => 'oxygen-sensors', 'o2 sensor' => 'oxygen-sensors', 'air fuel ratio sensor' => 'oxygen-sensors',
        'abs sensor' => 'abs-wheel-speed-sensors', 'wheel speed sensor' => 'abs-wheel-speed-sensors',
        'maf sensor' => 'mass-air-flow-sensors', 'mass air flow' => 'mass-air-flow-sensors', 'map sensor' => 'map-sensors',
        'manifold absolute pressure' => 'map-sensors', 'camshaft position sensor' => 'camshaft-position-sensors',
        'cam sensor' => 'camshaft-position-sensors', 'crankshaft position sensor' => 'crankshaft-position-sensors',
        'crank sensor' => 'crankshaft-position-sensors', 'knock sensor' => 'knock-sensors', 'throttle position sensor' => 'throttle-position-sensors',
        'tps sensor' => 'throttle-position-sensors', 'coolant temperature sensor' => 'coolant-temperature-sensors',
        'coolant temp sensor' => 'coolant-temperature-sensors', 'oil pressure sensor' => 'oil-pressure-sensors',
        'oil pressure switch' => 'oil-pressure-sensors', 'tpms' => 'tpms-sensors', 'tire pressure sensor' => 'tpms-sensors',
        'tire pressure monitor' => 'tpms-sensors', 'parking sensor' => 'parking-sensors', 'backup sensor' => 'parking-sensors',
        'vehicle speed sensor' => 'vehicle-speed-sensors', 'nox sensor' => 'nox-sensors', 'egt sensor' => 'exhaust-temperature-sensors',
        'exhaust temperature sensor' => 'exhaust-temperature-sensors', 'sensor' => 'sensors',

        // lighting
        'headlight bulb' => 'light-bulbs', 'led headlight bulb' => 'led-headlight-bulbs', 'led headlight' => 'led-headlight-bulbs',
        'h11 led' => 'led-headlight-bulbs', '9005 led' => 'led-headlight-bulbs', 'h4 led' => 'led-headlight-bulbs',
        'hid kit' => 'hid-kits', 'hid conversion' => 'hid-kits', 'xenon kit' => 'hid-kits',
        'head lamp' => 'headlights', 'headlamp' => 'headlights', 'headlight' => 'headlights', 'projector headlight' => 'headlights',
        'third brake light' => 'third-brake-lights', 'high mount stop' => 'third-brake-lights', 'tail light' => 'tail-lights',
        'taillight' => 'tail-lights', 'tail lamp' => 'tail-lights', 'brake light' => 'tail-lights', 'fog light' => 'fog-lights',
        'fog lamp' => 'fog-lights', 'light bar' => 'led-light-bars', 'work light' => 'off-road-work-lights', 'pod light' => 'off-road-work-lights',
        'off-road light' => 'off-road-work-lights', 'ditch light' => 'off-road-work-lights', 'turn signal' => 'turn-signal-lights',
        'side marker' => 'side-marker-lights', 'drl' => 'daytime-running-lights', 'daytime running' => 'daytime-running-lights',
        'corner light' => 'corner-lights', 'corner lamp' => 'corner-lights', 'license plate light' => 'license-plate-lights',
        'dome light' => 'interior-lights', 'map light' => 'interior-lights', 'interior light' => 'interior-lights', 'bulb' => 'light-bulbs',
        'led reflector' => 'led-accent-lights', 'reflector' => 'led-accent-lights', 'underglow' => 'led-accent-lights',
        'rock light' => 'led-accent-lights', 'led light' => 'led-accent-lights', 'lighting' => 'lighting',

        // suspension & steering
        'shock absorber' => 'shocks-struts', 'strut assembly' => 'shocks-struts', 'quick strut' => 'shocks-struts',
        'shock' => 'shocks-struts', 'strut' => 'shocks-struts', 'coilover' => 'coilovers', 'strut mount' => 'strut-mounts',
        'control arm' => 'control-arms', 'a-arm' => 'control-arms', 'wishbone' => 'control-arms', 'ball joint' => 'ball-joints',
        'coil spring' => 'coil-springs', 'lowering spring' => 'lowering-kits', 'lowering kit' => 'lowering-kits',
        'drop kit' => 'lowering-kits', 'leaf spring' => 'leaf-springs', 'air spring' => 'air-suspension', 'air bag suspension' => 'air-suspension',
        'air ride' => 'air-suspension', 'air strut' => 'air-suspension', 'sway bar' => 'sway-bars-links', 'stabilizer bar' => 'sway-bars-links',
        'stabilizer link' => 'sway-bars-links', 'end link' => 'sway-bars-links', 'bushing' => 'bushings',
        'wheel bearing' => 'wheel-bearings-hubs', 'hub assembly' => 'wheel-bearings-hubs', 'hub bearing' => 'wheel-bearings-hubs',
        'wheel hub' => 'wheel-bearings-hubs', 'trailing arm' => 'trailing-arms', 'torsion bar' => 'torsion-bars',
        'lift kit' => 'lift-kits', 'suspension lift' => 'lift-kits', 'body lift' => 'lift-kits', 'leveling kit' => 'leveling-kits',
        'level kit' => 'leveling-kits', 'suspension' => 'suspension',
        'tie rod' => 'tie-rods', 'rack and pinion' => 'rack-pinion', 'steering rack' => 'rack-pinion', 'steering gear' => 'steering-gearboxes',
        'gear box' => 'steering-gearboxes', 'power steering pump' => 'power-steering-pumps', 'power steering hose' => 'power-steering-hoses',
        'pressure line' => 'power-steering-hoses', 'eps motor' => 'electric-power-steering', 'electric power steering' => 'electric-power-steering',
        'idler arm' => 'idler-arms', 'pitman arm' => 'pitman-arms', 'drag link' => 'center-links-drag-links', 'center link' => 'center-links-drag-links',
        'steering column' => 'steering-columns', 'steering shaft' => 'steering-shafts', 'intermediate shaft' => 'steering-shafts',
        'steering stabilizer' => 'steering-stabilizers', 'steering damper' => 'steering-stabilizers', 'steering wheel cover' => 'steering-wheel-covers',
        'steering wheel' => 'steering-wheels', 'steering' => 'steering',

        // drivetrain & transmission
        'cv axle' => 'cv-axles', 'cv axle shaft' => 'cv-axles', 'cv axle assembly' => 'cv-axles', 'half shaft' => 'cv-axles', 'cv shaft' => 'cv-axles', 'cv boot' => 'cv-boots-joints', 'cv joint' => 'cv-boots-joints',
        'drive shaft' => 'driveshafts', 'driveshaft' => 'driveshafts', 'propeller shaft' => 'driveshafts', 'u-joint' => 'u-joints',
        'universal joint' => 'u-joints', 'axle shaft' => 'axle-shafts', 'differential cover' => 'differential-covers',
        'diff cover' => 'differential-covers', 'ring and pinion' => 'ring-pinion-gears', 'gear set' => 'ring-pinion-gears',
        'locker' => 'lockers', 'limited slip' => 'differentials', 'differential' => 'differentials', 'transfer case' => 'transfer-cases',
        'locking hub' => 'locking-hubs', 'carrier bearing' => 'carrier-bearings', 'center support bearing' => 'carrier-bearings',
        'drivetrain' => 'drivetrain',
        'clutch kit' => 'clutch-kits', 'clutch' => 'clutch-kits', 'flywheel' => 'flywheels', 'flexplate' => 'flywheels',
        'slave cylinder' => 'clutch-master-slave-cylinders', 'clutch master' => 'clutch-master-slave-cylinders',
        'torque converter' => 'torque-converters', 'transmission mount' => 'transmission-mounts', 'trans mount' => 'transmission-mounts',
        'transmission pan' => 'transmission-pans', 'transmission cooler' => 'transmission-coolers', 'trans cooler' => 'transmission-coolers',
        'shift solenoid' => 'transmission-solenoids', 'transmission solenoid' => 'transmission-solenoids', 'transmission rebuild' => 'transmission-rebuild-kits',
        'shifter' => 'shifters-linkage', 'shift cable' => 'shifters-linkage', 'shift linkage' => 'shifters-linkage',
        'transmission assembly' => 'complete-transmissions', 'transmission fluid' => 'transmission-fluid', 'atf' => 'transmission-fluid',
        'transmission' => 'transmission',

        // filters
        'oil filter' => 'oil-filters', 'cabin air filter' => 'cabin-air-filters', 'cabin filter' => 'cabin-air-filters',
        'pollen filter' => 'cabin-air-filters', 'engine air filter' => 'air-filters', 'air filter' => 'air-filters',
        'fuel filter' => 'fuel-filters', 'fuel water separator' => 'fuel-filters', 'transmission filter' => 'transmission-filters',
        'filter kit' => 'filter-kits', 'filter' => 'filters',

        // wheels & tires
        'lug nut' => 'lug-nuts-bolts', 'lug bolt' => 'lug-nuts-bolts', 'wheel stud' => 'lug-nuts-bolts', 'wheel lock' => 'wheel-locks',
        'wheel spacer' => 'wheel-spacers-adapters', 'wheel adapter' => 'wheel-spacers-adapters', 'hubcap' => 'hubcaps-wheel-covers',
        'hub cap' => 'hubcaps-wheel-covers', 'wheel cover' => 'hubcaps-wheel-covers', 'center cap' => 'center-caps',
        'valve stem' => 'valve-stems', 'spare tire' => 'spare-tire-parts', 'snow chain' => 'tire-chains', 'tire chain' => 'tire-chains',
        'rim' => 'wheels', 'alloy wheel' => 'wheels', 'tire' => 'tires', 'tyre' => 'tires',

        // body & exterior
        'side mirror' => 'mirrors', 'door mirror' => 'mirrors', 'mirror glass' => 'mirrors', 'towing mirror' => 'towing-mirrors',
        'tow mirror' => 'towing-mirrors', 'mirror' => 'mirrors', 'spoiler' => 'spoilers-wings', 'rear wing' => 'spoilers-wings',
        'trunk wing' => 'spoilers-wings', 'bumper cover' => 'bumpers-grilles', 'bumper' => 'bumpers-grilles', 'grille' => 'bumpers-grilles',
        'grill' => 'bumpers-grilles', 'front lip' => 'body-kits', 'splitter' => 'body-kits', 'diffuser' => 'body-kits', 'side skirt' => 'body-kits',
        'body kit' => 'body-kits', 'hood scoop' => 'hoods', 'hood' => 'hoods', 'fender flare' => 'fender-flares', 'fender liner' => 'fenders',
        'fender' => 'fenders', 'outside door handle' => 'door-handles', 'door handle' => 'door-handles', 'door shell' => 'doors',
        'tailgate' => 'tailgates', 'trunk lid' => 'trunk-lids', 'rocker panel' => 'quarter-rocker-panels', 'quarter panel' => 'quarter-rocker-panels',
        'running board' => 'running-boards-side-steps', 'side step' => 'running-boards-side-steps', 'nerf bar' => 'running-boards-side-steps',
        'tonneau' => 'tonneau-covers', 'bed cover' => 'tonneau-covers', 'bed liner' => 'bed-liners-mats', 'bedliner' => 'bed-liners-mats',
        'bed mat' => 'bed-liners-mats', 'roof rack' => 'roof-racks', 'cross bar' => 'roof-racks', 'crossbar' => 'roof-racks',
        'mud flap' => 'mud-flaps-splash-guards', 'splash guard' => 'mud-flaps-splash-guards', 'bug deflector' => 'bug-deflectors-window-visors',
        'hood protector' => 'bug-deflectors-window-visors', 'window visor' => 'bug-deflectors-window-visors', 'rain guard' => 'bug-deflectors-window-visors',
        'vent visor' => 'bug-deflectors-window-visors', 'windshield' => 'windshields-glass', 'window glass' => 'windshields-glass',
        'window regulator' => 'window-regulators', 'weatherstrip' => 'weatherstripping', 'door seal' => 'weatherstripping',
        'emblem' => 'emblems-badges', 'badge' => 'emblems-badges', 'car cover' => 'car-covers', 'body' => 'body-exterior',

        // wipers
        'wiper blade' => 'wiper-blades', 'windshield wiper' => 'wiper-blades', 'wiper arm' => 'wiper-arms', 'wiper motor' => 'wiper-motors',
        'wiper linkage' => 'wiper-linkages', 'wiper transmission' => 'wiper-linkages', 'washer pump' => 'washer-pumps',
        'washer reservoir' => 'washer-reservoirs', 'washer bottle' => 'washer-reservoirs', 'washer fluid' => 'windshield-washer-fluid',
        'wiper' => 'wiper-blades',

        // interior
        'floor mat' => 'floor-mats', 'floor liner' => 'floor-mats', 'cargo liner' => 'cargo-liners', 'trunk mat' => 'cargo-liners',
        'seat cover' => 'seat-covers', 'seat belt' => 'seat-belts', 'racing seat' => 'racing-seats-harnesses', 'harness' => 'racing-seats-harnesses',
        'seat' => 'seats', 'dash cover' => 'dash-covers-trim', 'dash trim' => 'dash-covers-trim', 'door panel' => 'door-panels',
        'center console' => 'center-consoles', 'armrest' => 'center-consoles', 'gauge pod' => 'gauges', 'boost gauge' => 'gauges',
        'gauge' => 'gauges', 'shift knob' => 'shift-knobs-boots', 'shift boot' => 'shift-knobs-boots', 'sun visor' => 'sun-visors',
        'headliner' => 'headliners', 'carpet' => 'carpet', 'pedal' => 'pedals', 'inside door handle' => 'interior-door-handles',
        'interior door handle' => 'interior-door-handles', 'interior' => 'interior',

        // audio & electronics
        'head unit' => 'car-stereos', 'car stereo' => 'car-stereos', 'radio' => 'car-stereos', 'carplay' => 'car-stereos',
        'speaker' => 'speakers', 'subwoofer' => 'subwoofers', 'sub box' => 'subwoofers', 'amplifier' => 'amplifiers', 'amp kit' => 'dash-kits-install-parts',
        'dash kit' => 'dash-kits-install-parts', 'wiring interface' => 'dash-kits-install-parts', 'backup camera' => 'backup-cameras',
        'reverse camera' => 'backup-cameras', 'rear view camera' => 'backup-cameras', 'dash cam' => 'dash-cams', 'dashcam' => 'dash-cams',
        'gps' => 'gps-navigation', 'remote start' => 'remote-starters', 'car alarm' => 'car-alarms-security', 'immobilizer' => 'car-alarms-security',
        'radar detector' => 'radar-detectors', 'antenna' => 'antennas', 'phone mount' => 'phone-mounts-chargers', 'phone holder' => 'phone-mounts-chargers',
        'car charger' => 'phone-mounts-chargers',

        // towing
        'trailer hitch' => 'trailer-hitches', 'receiver hitch' => 'trailer-hitches', 'hitch ball' => 'ball-mounts-hitch-balls',
        'ball mount' => 'ball-mounts-hitch-balls', 'trailer wiring' => 'trailer-wiring', '7-way' => 'trailer-wiring', '4-way' => 'trailer-wiring',
        'brake controller' => 'brake-controllers', 'fifth wheel' => 'fifth-wheel-gooseneck-hitches', 'gooseneck' => 'fifth-wheel-gooseneck-hitches',
        'weight distribution' => 'weight-distribution-hitches', 'tow strap' => 'tow-straps-recovery-gear', 'recovery strap' => 'tow-straps-recovery-gear',
        'recovery board' => 'tow-straps-recovery-gear', 'shackle' => 'tow-straps-recovery-gear', 'winch' => 'winches', 'hitch' => 'trailer-hitches',

        // performance
        'tuner' => 'tuners-programmers', 'programmer' => 'tuners-programmers', 'performance chip' => 'tuners-programmers',
        'turbo kit' => 'turbo-kits', 'supercharger kit' => 'supercharger-kits', 'nitrous' => 'nitrous-systems', 'blow off valve' => 'blow-off-valves',
        'blow-off valve' => 'blow-off-valves', 'bov' => 'blow-off-valves', 'wastegate' => 'wastegates', 'boost controller' => 'boost-controllers',
        'strut bar' => 'strut-tower-bars', 'strut tower brace' => 'strut-tower-bars', 'short shifter' => 'short-shifters',

        // hybrid & EV
        'hybrid battery' => 'hybrid-batteries', 'ev charger' => 'ev-chargers', 'level 2 charger' => 'ev-chargers', 'wall charger' => 'ev-chargers',
        'charging cable' => 'ev-charging-cables-adapters', 'j1772' => 'ev-charging-cables-adapters', 'nacs adapter' => 'ev-charging-cables-adapters',
        'charge port' => 'ev-charge-ports', 'charging port' => 'ev-charge-ports', 'inverter' => 'inverters-converters', 'dc-dc converter' => 'inverters-converters',

        // fluids, care, tools, accessories
        'motor oil' => 'motor-oil', 'engine oil' => 'motor-oil', '5w-30' => 'motor-oil', '5w30' => 'motor-oil', '0w-20' => 'motor-oil',
        'power steering fluid' => 'power-steering-fluid', 'gear oil' => 'gear-oil', 'differential fluid' => 'gear-oil', 'grease' => 'grease-lubricants',
        'lubricant' => 'grease-lubricants', 'fuel additive' => 'fuel-additives', 'fuel injector cleaner' => 'fuel-additives',
        'fuel stabilizer' => 'fuel-additives', 'diesel exhaust fluid' => 'diesel-exhaust-fluid', 'sealant' => 'sealants-adhesives',
        'thread locker' => 'sealants-adhesives', 'rtv' => 'sealants-adhesives', 'epoxy' => 'sealants-adhesives', 'degreaser' => 'cleaners-degreasers',
        'brake cleaner' => 'cleaners-degreasers', 'car wash' => 'car-wash-soap', 'wash soap' => 'car-wash-soap', 'car soap' => 'car-wash-soap',
        'wax' => 'wax-sealants', 'paint sealant' => 'wax-sealants', 'polish' => 'polish-compounds', 'compound' => 'polish-compounds',
        'ceramic coating' => 'ceramic-coatings', 'interior cleaner' => 'interior-cleaners', 'leather cleaner' => 'interior-cleaners',
        'tire shine' => 'tire-wheel-cleaners', 'wheel cleaner' => 'tire-wheel-cleaners', 'glass cleaner' => 'glass-cleaners',
        'microfiber' => 'microfiber-towels', 'detailing brush' => 'detailing-tools', 'buffer' => 'detailing-tools', 'touch up paint' => 'touch-up-paint',
        'touch-up paint' => 'touch-up-paint', 'air freshener' => 'air-fresheners',
        'obd2' => 'obd2-scanners-diagnostics', 'obd ii' => 'obd2-scanners-diagnostics', 'code reader' => 'obd2-scanners-diagnostics',
        'diagnostic scanner' => 'obd2-scanners-diagnostics', 'scan tool' => 'obd2-scanners-diagnostics', 'socket set' => 'socket-sets',
        'torque wrench' => 'torque-wrenches', 'impact wrench' => 'power-tools', 'impact driver' => 'power-tools', 'floor jack' => 'jacks-jack-stands',
        'jack stand' => 'jacks-jack-stands', 'bottle jack' => 'jacks-jack-stands', 'air compressor' => 'air-compressors-inflators',
        'tire inflator' => 'air-compressors-inflators', 'multimeter' => 'multimeters-testers', 'test light' => 'multimeters-testers',
        'wrench set' => 'hand-tools', 'ratchet' => 'hand-tools', 'pliers' => 'hand-tools', 'puller' => 'specialty-tools',
        'creeper' => 'shop-equipment', 'engine hoist' => 'shop-equipment', 'oil drain pan' => 'shop-equipment',
        'trunk organizer' => 'cargo-organizers', 'cargo net' => 'cargo-organizers', 'emergency kit' => 'emergency-safety-kits',
        'first aid' => 'emergency-safety-kits', 'license plate frame' => 'license-plate-frames', 'pet barrier' => 'pet-accessories',
        'dog seat cover' => 'pet-accessories', 'seat organizer' => 'seat-organizers', 'seat back organizer' => 'seat-organizers',
        'sunshade' => 'sunshades', 'sun shade' => 'sunshades', 'windshield shade' => 'sunshades',
    ];

    private ?Collection $categories = null;

    public function __construct(private AiClient $ai) {}

    /**
     * Resolve many distinct hint strings at once.
     *
     * @param  array<int,string>  $hints
     * @param  bool  $useAi  run the AI clean-up pass for anything deterministic matching missed
     * @return array<string,int>  hint (lower-cased, trimmed) => category id  — only successful matches
     */
    public function resolveMany(array $hints, bool $useAi = true): array
    {
        $cats = $this->categories();
        $resolved = [];
        $unresolved = [];

        foreach (array_unique(array_map(fn ($h) => Str::lower(trim($h)), $hints)) as $hint) {
            if ($hint === '') {
                continue;
            }
            $id = $this->matchDeterministic($hint, $cats);
            if ($id) {
                $resolved[$hint] = $id;
            } else {
                $unresolved[] = $hint;
            }
        }

        if ($useAi && $unresolved && $this->ai->isConfigured()) {
            $taxHash = substr(md5($cats->pluck('slug')->sort()->implode(',')), 0, 8);
            $ask = [];

            // Re-use anything we've classified before (a hint string is stable across imports).
            foreach ($unresolved as $hint) {
                $cached = Cache::get("catres:{$taxHash}:".md5($hint));
                if ($cached === '-') {
                    continue;                       // known "no match" — don't ask again
                }
                if ($cached && ($cat = $cats->firstWhere('slug', $cached))) {
                    $resolved[$hint] = $cat->id;
                } else {
                    $ask[] = $hint;
                }
            }

            if ($ask) {
                $answers = $this->matchWithAi($ask, $cats);
                foreach ($ask as $hint) {
                    $slug = $answers[$hint] ?? null;
                    $cat = $slug ? $cats->firstWhere('slug', $slug) : null;
                    Cache::put("catres:{$taxHash}:".md5($hint), $cat?->slug ?? '-', now()->addDays(90));
                    if ($cat) {
                        $resolved[$hint] = $cat->id;
                    }
                }
            }
        }

        return $resolved;
    }

    /** Single-hint convenience (deterministic only — no per-row AI calls). */
    public function resolve(string ...$hints): ?int
    {
        $cats = $this->categories();
        foreach ($hints as $hint) {
            $id = $this->matchDeterministic(Str::lower(trim($hint)), $cats);
            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private function matchDeterministic(string $hint, Collection $cats): ?int
    {
        if ($hint === '') {
            return null;
        }

        // Category paths from every platform: "A > B > Brake Pads", "A/B/Brake Pads", "Lighting|Headlights",
        // Amazon item types "automotive-headlight-assemblies" — try the leaf first, then the whole path.
        $hint = Str::lower(trim((string) preg_replace('/\s+/', ' ', str_replace(['_', '"'], [' ', ''], $hint))));
        if (! str_contains($hint, ' ') && str_contains($hint, '-')) {
            $hint = str_replace('-', ' ', $hint);
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*(?:>|\/|\||;|,)\s*/', $hint) ?: [])));
        $leaf = $parts ? end($parts) : $hint;
        $candidates = array_values(array_unique(array_filter([$leaf, ...array_reverse($parts), $hint])));

        // 1. exact name / slug
        foreach ($candidates as $c) {
            $hit = $cats->first(fn ($cat) => Str::lower($cat->name) === $c || $cat->slug === Str::slug($c));
            if ($hit) {
                return $hit->id;
            }
        }

        // 2. The longest phrase found in the hint wins, whether it's a category name (whole words, also
        //    singular: "Headlight" for "Headlights", and each 2+ word part of "Wheel Bearings & Hubs")
        //    or a keyword. So "front wheel bearing" → Wheel Bearings & Hubs, not the shorter "Wheels".
        //    On a tie a sub-category beats its department, and a category name beats a keyword.
        $best = null;
        $bestScore = 0;
        foreach ($cats as $cat) {
            foreach ($this->nameForms($cat->name) as $form) {
                if (preg_match('/(?<![a-z0-9])'.preg_quote($form, '/').'(?![a-z0-9])/', $hint)) {
                    $score = Str::length($form) * 100 + ($cat->parent_id ? 20 : 0) + 10;
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$cat->id, $score];
                    }
                }
            }
        }
        foreach (self::KEYWORDS as $needle => $slug) {
            if (Str::length($needle) * 100 + 30 <= $bestScore) {
                continue;
            }
            if (preg_match('/(?<![a-z0-9])'.preg_quote(trim($needle), '/').'/', $hint) && ($cat = $cats->firstWhere('slug', $slug))) {
                $score = Str::length($needle) * 100 + ($cat->parent_id ? 20 : 0);
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$cat->id, $score];
                }
            }
        }

        return $best;
    }

    /** @return list<string> lower-case ways a category name can appear in a title */
    private function nameForms(string $name): array
    {
        static $memo = [];
        if (isset($memo[$name])) {
            return $memo[$name];
        }
        $key = $name;
        $name = Str::lower($name);
        $name = trim((string) preg_replace('/\s*\([^)]*\)/', '', $name)); // "engine control modules (ecu)"
        $singular = fn (string $s) => implode(' ', array_map(fn ($w) => Str::singular($w), explode(' ', $s)));

        $forms = [$name, $singular($name), str_replace(' & ', ' and ', $name)];
        $parts = preg_split('/\s*(?:&|,| and )\s*/', $name) ?: [];
        if (count($parts) > 1) {
            foreach ($parts as $part) {
                if (str_contains($part, ' ')) { // "wheel bearings", never a lone "hubs"
                    array_push($forms, $part, $singular($part));
                }
            }
        }

        return $memo[$key] = array_values(array_filter(array_unique($forms), fn ($f) => Str::length($f) >= 3));
    }

    /**
     * @param  array<int,string>  $hints
     * @return array<string,string>  hint => category slug
     */
    private function matchWithAi(array $hints, Collection $cats): array
    {
        $taxonomy = $cats->map(fn ($c) => "- {$c->slug}: {$c->name}".($c->parent_id ? ' (under '.($cats->firstWhere('id', $c->parent_id)->name ?? '').')' : ''))->implode("\n");

        $valid = $cats->pluck('slug')->flip();
        $answers = [];

        // Small batches so every item gets an answer within the reply size.
        foreach (array_chunk(array_values($hints), 25) as $chunk) {
            $list = collect($chunk)->map(fn ($h) => '- '.$h)->implode("\n");
            try {
                $out = $this->ai->completeJson(
                    'You classify auto-parts products into a fixed category taxonomy. Each input is a product title and/or a category '
                    .'path from another store. Return a compact JSON object mapping each input string exactly as given to the single '
                    .'best category slug from the taxonomy (prefer the most specific sub-category), or null if it is not an auto part '
                    .'or nothing fits. Use only slugs from the list. No explanation.',
                    "Taxonomy:\n{$taxonomy}\n\nClassify these:\n{$list}",
                    1200,
                );
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            foreach ((array) $out as $hint => $slug) {
                if (is_string($slug) && $valid->has($slug)) {
                    $answers[Str::lower(trim((string) $hint))] = $slug;
                }
            }
        }

        return $answers;
    }

    private function categories(): Collection
    {
        return $this->categories ??= Category::query()
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('categories', 'is_active'),
                fn ($q) => $q->where('is_active', true))
            ->get(['id', 'name', 'slug', 'parent_id']);
    }
}
