from pathlib import Path
from PIL import Image
from io import BytesIO
import time,json
root=Path('public/bitcraft-map/detail/20261009010358')
files=list((root/'l0').glob('*.png'))
samples=[sorted(files,key=lambda p:p.stat().st_size)[i] for i in [0,len(files)//4,len(files)//2,3*len(files)//4,-1]] + list((root/'l3').glob('*.png'))[:3]
results=[]
for p in samples:
 image=Image.open(p).convert('RGB')
 out=BytesIO(); start=time.perf_counter(); image.save(out,format='WEBP',lossless=True,quality=100,method=4)
 encoded=out.getvalue(); decoded=Image.open(BytesIO(encoded)).convert('RGB')
 results.append({'file':str(p.relative_to(root)),'png':p.stat().st_size,'webp':len(encoded),'ms':round((time.perf_counter()-start)*1000),'identical':image.tobytes()==decoded.tobytes()})
print(json.dumps(results,indent=2))
