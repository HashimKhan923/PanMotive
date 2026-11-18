import json, os, time, urllib.request
from concurrent.futures import ThreadPoolExecutor

here = os.path.dirname(os.path.abspath(__file__))
cache = os.path.join(here, 'cache')
os.makedirs(cache, exist_ok=True)

ids = json.load(open(os.path.join(here, 'make_ids.json')))
extra = {'AMC': [[0, 'car']], 'Sterling': [[0, 'car']]}  # resolved below
for t in ('car', 'mpv', 'truck'):
    for r in json.load(open(os.path.join(here, f'makes_{t}.json')))['Results']:
        n = r['MakeName'].strip().upper()
        if n == 'AMERICAN MOTORS':
            ids.setdefault('AMC', []).append([r['MakeId'], t])
        if n == 'STERLING MOTOR CAR':
            ids.setdefault('Sterling', []).append([r['MakeId'], t])
for drop in ('Lancia', 'Mahindra', 'Scout'):
    ids.pop(drop, None)
json.dump(ids, open(os.path.join(here, 'make_ids_final.json'), 'w'))

jobs = []
for make, pairs in ids.items():
    for mid, t in pairs:
        for y in range(1981, 2028):
            jobs.append((make, mid, t, y))


def get(job):
    make, mid, t, y = job
    path = os.path.join(cache, f'{mid}_{t}_{y}.json')
    if os.path.exists(path) and os.path.getsize(path) > 0:
        return 'cached'
    url = f'https://vpic.nhtsa.dot.gov/api/vehicles/GetModelsForMakeIdYear/makeId/{mid}/modelyear/{y}/vehicletype/{t}?format=json'
    for attempt in range(5):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'catalog-seeder/1.0'}), timeout=60) as r:
                data = json.loads(r.read().decode('utf-8'))
            with open(path, 'w') as f:
                json.dump(data.get('Results', []), f)
            return 'ok'
        except Exception as e:
            time.sleep(2 * (attempt + 1))
    return 'fail'


print(len(jobs), 'requests', flush=True)
done = {'ok': 0, 'cached': 0, 'fail': 0}
with ThreadPoolExecutor(6) as ex:
    for i, res in enumerate(ex.map(get, jobs)):
        done[res] += 1
        if i % 500 == 0:
            print(i, done, flush=True)
print('FINISHED', done, flush=True)
