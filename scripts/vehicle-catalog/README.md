# Vehicle catalog (Year / Make / Model)

Builds `database/seeders/data/vehicles.json`, which `VehicleMakeSeeder` loads.

Sources (both free US-government data):
- NHTSA vPIC: every make/model per model year (cars, MPVs/SUVs, trucks), 1981+.
- EPA fueleconomy.gov `vehicles.csv`: the years each model was really on sale (fixes NHTSA's wide VIN ranges on older cars).

Refresh once a year (new model year):

```bash
cd scripts/vehicle-catalog
for t in car mpv truck; do curl -s "https://vpic.nhtsa.dot.gov/api/vehicles/GetMakesForVehicleType/$t?format=json" -o makes_$t.json; done
python -I makes.py                 # which NHTSA make ids are real US car/truck brands
python -I fetch.py                 # ~7,500 requests, cached in cache/
mkdir -p epa && curl -s -o epa/vehicles.csv.zip https://www.fueleconomy.gov/feg/epadata/vehicles.csv.zip && (cd epa && unzip -o vehicles.csv.zip)
python -I build.py ../../database/seeders/data/vehicles.json --noepa   # also lists models EPA doesn't know, to review
php artisan db:seed --class=VehicleMakeSeeder
```

`build.py` holds the clean-up rules: junk/heavy-truck filters, Canada/Mexico-only models (`DROP`), renames, the
Silverado/Sierra 1500 / 2500 HD / 3500 HD split, and Scion (NHTSA files Scion under Toyota). Update `LAST` for a new model year.
