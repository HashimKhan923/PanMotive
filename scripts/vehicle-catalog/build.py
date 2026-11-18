"""Merge NHTSA vPIC (make/model/year) with EPA fueleconomy.gov (real sale years) into vehicles.json.

Usage: python -I build.py <out.json> [--report]
"""
import collections, csv, json, os, re, sys

here = os.path.dirname(os.path.abspath(__file__))
epa_csv = os.path.join(here, 'epa', 'vehicles.csv')
ids = json.load(open(os.path.join(here, 'make_ids_final.json')))
REPORT = '--report' in sys.argv

FIRST, LAST = 1981, 2027

# Not vehicles a parts shopper owns: bare chassis, hearses, limo conversions, test entries.
JUNK = re.compile(r'(chassis|hearse|limo|incomplete|cutaway|stripped|motorhome|motor home|\bbus\b|school|'
                  r'\bglider\b|\bn/a\b|unknown|^test|\bprototype\b|ambulance)', re.I)

# Heavy-duty trucks and vans: EPA doesn't test them, so EPA years can't be used to trim them.
HEAVY = re.compile(r'(\b[fe]-?[2-7]50\b|\b[2-5]500\b|super duty|\bhd\b|express|savana|sprinter|transit|promaster|'
                   r'econoline|\bnpr\b|\bnqr\b|\bfrr\b|\bftr\b|\bw[3-7]\d{3}|\blcf\b|low cab|medium duty|\bc[4-8]500|'
                   r'kodiak|topkick|\bt-series|\bf-?650|\bf-?750|\bh1\b|\bh2\b|\bnv\d|nv200|city express|e-series|'
                   r'g-series|g\d{2,3}0\b|chevy van|ram van|ram wagon|b\d50|grumman|step van|p-?\d0|forward)', re.I)

def key(name):
    return re.sub(r'[^a-z0-9]', '', name.lower())

def clean(name):
    name = re.sub(r'\s+', ' ', name.replace(' ', ' ')).strip(' -/')
    # "LEGANZA" -> "Leganza"; short codes like "GTR", "SRX", "RAV4" stay as they are.
    return ' '.join(w.capitalize() if len(w) >= 5 and w.isalpha() and w.isupper() else w for w in name.split(' '))

# ---------------- vPIC ----------------
vpic = collections.defaultdict(lambda: collections.defaultdict(set))       # make -> key -> years
display = collections.defaultdict(lambda: collections.defaultdict(collections.Counter))
for make, pairs in ids.items():
    for mid, t in pairs:
        for y in range(FIRST, LAST + 1):
            path = os.path.join(here, 'cache', f'{mid}_{t}_{y}.json')
            if not os.path.exists(path):
                print('MISSING', path, file=sys.stderr)
                continue
            for r in json.load(open(path)):
                n = clean(r.get('Model_Name') or '')
                if not n or JUNK.search(n):
                    continue
                k = key(n)
                if not k:
                    continue
                vpic[make][k].add(y)
                display[make][k][n] += 1

# ---------------- EPA ----------------
EPA_MAKE = {'Ram': 'RAM', 'Mini': 'MINI', 'smart': 'Smart', 'McLaren Automotive': 'McLaren', 'INEOS Automotive': 'INEOS',
            'American Motors Corporation': 'AMC', 'CODA Automotive': 'Coda', 'Vinfast': 'VinFast', 'Panoz Auto-Development': 'Panoz',
            'Saleen Performance': 'Saleen'}
epa = collections.defaultdict(lambda: collections.defaultdict(set))
epa_display = collections.defaultdict(lambda: collections.defaultdict(collections.Counter))
SUFFIX = re.compile(r'\b(2wd|4wd|awd|fwd|rwd|ffv|pickup|cab chassis|wagon|convertible|coupe|hatchback|sedan|van|'
                    r'conversion|hybrid|plug-in|phev|sport|turbo|diesel|cng|lpg|fe|lwb|swb|\d\s?door|[24]dr|long range|'
                    r'performance|xl|quattro|xdrive|4matic|sdrive)\b', re.I)
for r in csv.DictReader(open(epa_csv, encoding='utf-8', errors='replace')):
    mk = EPA_MAKE.get(r['make'], r['make'])
    y = int(r['year'])
    base = clean(r['baseModel'] or r['model'])
    epa[mk][key(base)].add(y)
    epa_display[mk][key(base)][base] += 1
    full = clean(SUFFIX.sub('', r['model']))
    if full:
        epa[mk][key(full)].add(y)

def epa_years(make, k):
    ys = set()
    for ek, years in epa.get(make, {}).items():
        if ek == k or (len(k) >= 3 and ek.startswith(k)):
            ys |= years
    return ys

# ---------------- merge ----------------
out = {}
trimmed = []
for make in sorted(vpic):
    models = {}
    for k, years in vpic[make].items():
        name = display[make][k].most_common(1)[0][0]
        ey = epa_years(make, k)
        if ey and not HEAVY.search(name):
            lo, hi = min(ey), max(ey)
            # Trust EPA for when a car was really on sale (vPIC VIN ranges run wide on older cars).
            upper = LAST if hi >= 2024 else hi  # still on sale: next model years are fine
            keep = {y for y in years if (y < 1984 and lo <= 1985) or y in ey or (hi < y <= upper)}
            if keep != years:
                trimmed.append((make, name, sorted(years - keep)))
            # Fill years NHTSA skipped when EPA lists this exact model.
            years = keep | {y for y in epa.get(make, {}).get(k, set()) if FIRST <= y <= LAST}
        if years:
            models[name] = years
    out[make] = models

# Heavy commercial trucks, Canada/Mexico-only models and bad VIN entries a US parts shopper never owns.
DROP = {
 'Ford': {"'34", 'Recreational Vehicle', 'B-750', 'E-550', 'F-590', 'F-700', 'F-800'},
 'Chevrolet': {'3 ton', 'Aluminum Tilt', 'B7', 'C5', 'C6', 'C7', 'Forward Control', 'Heavy Conventional', 'Hi-Cube', 'Kalos',
               'Matiz', 'Military Truck', 'Onix', 'Orlando', 'P - Series', 'P6S', 'S6', 'S7', 'U100', 'Silverado HD', 'Silverado LTD', 'Optra'},
 'GMC': {'Aluminum Tilt', 'C5', 'C6', 'C7', 'Forward Control', 'Heavy Conventional', 'Hi-Cube', 'Magna Van', 'P Truck Forward', 'S6',
         'Value Van', 'Cruise Origin AV', 'Electric Vehicle', 'Geo Tracker', 'Sierra HD', 'Sierra Limited'},
 'Isuzu': {'F6', 'F7', 'FSR', 'FTR/FVR', 'FTR/FVR/EVR', 'H-Series', 'T6F', 'T7F', 'T8F', 'FRR'},
 'Volvo': {'Cab Behind Engine', 'Cab Over Engine HT', 'Cab Over Engine LT', 'F12 w/F7 Cab', 'F6 w/F7 Cab', 'VS', 'VT', 'C70 / C30'},
 'Hyundai': {'Xcient', 'Stellar'}, 'Tesla': {'Semi', 'Cybercab'},
 'Daewoo': {'G2X', 'Laganza V-car', 'Leganza V-200 & variants/derivatives'},
 'Bentley': {'ARMOURED ARNAGE', 'Roll Royce Silver Seraph', 'Rolls-Royce Park Ward'},
 'Bugatti': {'EB110'}, 'Lamborghini': {'147'}, 'Lotus': {'Eagle', 'MEL', 'Monaco', 'Monza', 'Spa', '340R'},
 'Toyota': {'FCHV-adv'}, 'Pontiac': {'Acadian', 'Laurentian', 'Matiz', 'Tempest', 'Pursuit', 'Catalina'},
 'Mitsubishi': {'RVR'}, 'Nissan': {'Micra', 'X-Trail'}, 'Smart': {'smart'}, 'Fiat': {'Ducato', 'Freemont'},
 'Dodge': {'RD200 / RD250'}, 'Subaru': {'Standard'},
 'Chrysler': {'SALON', 'Daytona', 'Dynasty', 'Intrepid', 'Neon', 'Shadow', 'Stratus', 'Viper', 'Vision', 'Grand Caravan'},
}
HEAVY_CODE = re.compile(r'^(A|AT|B|C|CF|CFT|CL|CLT|CT|F|FT|L|LA|LL|LLA|LLS|LN|LNT|LS|LT|LTA|LTL|LTLA|LTLS|LTS|P|LP|LPS)\d{3,4}$')
RENAME = {('Dodge', 'ST.REGIS'): 'St. Regis', ('Chrysler', 'E-CLASS'): 'E Class', ('Chevrolet', 'GMT-400'): 'C/K Pickup', ('GMC', 'GMT-400'): 'C/K Pickup'}
for make, models in out.items():
    for name in list(models):
        if name in DROP.get(make, set()) or (make in ('Ford', 'Mercedes-Benz') and HEAVY_CODE.match(name)):
            del models[name]
        elif (make, name) in RENAME:
            models.setdefault(RENAME[(make, name)], set()).update(models.pop(name))

# Scion: VINs say Toyota, so NHTSA files Scion cars under Toyota. EPA lists them on their own.
scion = {}
for k, years in epa['Scion'].items():
    name = epa_display['Scion'][k].most_common(1)[0][0] if epa_display['Scion'][k] else None
    if name:
        scion[name] = years
for name in list(out.get('Toyota', {})):
    if key(name).removeprefix('scion') in {key(n) for n in scion}:
        del out['Toyota'][name]
out['Scion'] = scion

# GM pickups: NHTSA lumps every Silverado/Sierra together; shoppers buy parts for a specific one.
def split_gm(make, base):
    models = out.get(make, {})
    years = set()
    for name in list(models):
        if key(name) in (key(base), key(base) + 'pickup'):
            years |= models.pop(name)
    if not years:
        return
    lo = max(min(years), 1988 if base == 'Sierra' else 1999)
    plan = {
        f'{base} 1500': (lo, LAST),
        f'{base} 2500': (lo, 2004),
        f'{base} 2500 HD': (2001, LAST),
        f'{base} 3500': (2001, 2006),
        f'{base} 3500 HD': (2007, LAST),
    }
    if base == 'Silverado':
        plan[f'{base} 1500'] = (1999, LAST)
        plan[f'{base} 2500'] = (1999, 2004)
    for name, (a, b) in plan.items():
        ys = {y for y in years if a <= y <= b}
        if ys:
            models.setdefault(name, set()).update(ys)

split_gm('Chevrolet', 'Silverado')
split_gm('GMC', 'Sierra')

def ranges(years):
    ys = sorted(years)
    out, start, prev = [], ys[0], ys[0]
    for y in ys[1:]:
        if y != prev + 1:
            out.append(f'{start}' if start == prev else f'{start}-{prev}')
            start = y
        prev = y
    out.append(f'{start}' if start == prev else f'{start}-{prev}')
    return ','.join(out)

final = {mk: {m: ranges(ys) for m, ys in sorted(models.items(), key=lambda x: x[0].lower()) if ys}
         for mk, models in sorted(out.items()) if models}
json.dump(final, open(sys.argv[1], 'w', encoding='utf-8'), indent=1, ensure_ascii=False)

n_models = sum(len(v) for v in final.values())
n_vehicles = sum(len(ys) for v in out.values() for ys in v.values())
print(len(final), 'makes,', n_models, 'models,', n_vehicles, 'model-years')
if REPORT:
    for t in trimmed[:400]:
        print('TRIM', t)

if '--noepa' in sys.argv:
    for make in sorted(out):
        if make == 'Scion':
            continue
        names = [f"{n}[{ranges(ys)}]" for n, ys in sorted(out[make].items()) if not epa_years(make, key(n))]
        if names:
            print('NOEPA', make, '|', '; '.join(names))
