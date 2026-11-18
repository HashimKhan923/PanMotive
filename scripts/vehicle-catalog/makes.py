import json, sys, os
here = os.path.dirname(os.path.abspath(__file__))

# Real makes sold as cars / SUVs / pickups / vans in the US since 1981 (incl. discontinued brands).
WANT = {
 'ACURA':'Acura','ALFA ROMEO':'Alfa Romeo','AMERICAN MOTORS (AMC)':'AMC','AMC':'AMC','ASTON MARTIN':'Aston Martin','AUDI':'Audi',
 'BENTLEY':'Bentley','BMW':'BMW','BUGATTI':'Bugatti','BUICK':'Buick','CADILLAC':'Cadillac','CHEVROLET':'Chevrolet','CHRYSLER':'Chrysler',
 'DAEWOO':'Daewoo','DAIHATSU':'Daihatsu','DATSUN':'Datsun','DELOREAN':'DeLorean','DODGE':'Dodge','EAGLE':'Eagle','FERRARI':'Ferrari',
 'FIAT':'Fiat','FISKER':'Fisker','FORD':'Ford','GENESIS':'Genesis','GEO':'Geo','GMC':'GMC','HONDA':'Honda','HUMMER':'Hummer',
 'HYUNDAI':'Hyundai','INEOS':'INEOS','INEOS AUTOMOTIVE':'INEOS','INFINITI':'Infiniti','ISUZU':'Isuzu','JAGUAR':'Jaguar','JEEP':'Jeep',
 'KARMA':'Karma','KIA':'Kia','LAMBORGHINI':'Lamborghini','LAND ROVER':'Land Rover','LEXUS':'Lexus','LINCOLN':'Lincoln','LOTUS':'Lotus',
 'LUCID':'Lucid','MASERATI':'Maserati','MAYBACH':'Maybach','MAZDA':'Mazda','MCLAREN':'McLaren','MCLAREN AUTOMOTIVE':'McLaren',
 'MERCEDES-BENZ':'Mercedes-Benz','MERCURY':'Mercury','MERKUR':'Merkur','MINI':'MINI','MITSUBISHI':'Mitsubishi','NISSAN':'Nissan',
 'OLDSMOBILE':'Oldsmobile','PEUGEOT':'Peugeot','PLYMOUTH':'Plymouth','POLESTAR':'Polestar','PONTIAC':'Pontiac','PORSCHE':'Porsche',
 'RAM':'RAM','RENAULT':'Renault','RIVIAN':'Rivian','ROLLS ROYCE':'Rolls-Royce','ROLLS-ROYCE':'Rolls-Royce','SAAB':'Saab','SATURN':'Saturn',
 'SCION':'Scion','SMART':'Smart','STERLING':'Sterling','SUBARU':'Subaru','SUZUKI':'Suzuki','TESLA':'Tesla','TOYOTA':'Toyota',
 'VINFAST':'VinFast','VOLKSWAGEN':'Volkswagen','VOLVO':'Volvo','YUGO':'Yugo','PANOZ':'Panoz','SALEEN':'Saleen','SRT':'SRT',
 'SCOUT':'Scout','LANCIA':'Lancia','CODA':'Coda','MAHINDRA':'Mahindra','LOTUS CARS':'Lotus',
}
found = {}
allnames = set()
for t in ('car','mpv','truck'):
    for r in json.load(open(os.path.join(here, f'makes_{t}.json')))['Results']:
        name = r['MakeName'].strip().upper()
        allnames.add(name)
        if name in WANT:
            found.setdefault(WANT[name], set()).add((r['MakeId'], t))
out = {k: sorted(v) for k, v in sorted(found.items())}
json.dump({k: [list(x) for x in v] for k, v in out.items()}, open(os.path.join(here, 'make_ids.json'), 'w'), indent=0)
missing = sorted(set(WANT.values()) - set(found))
print(len(out), 'makes found; missing:', missing)
for k, v in out.items():
    print(k, v)
if len(sys.argv) > 1:
    print([n for n in sorted(allnames) if sys.argv[1].upper() in n])
