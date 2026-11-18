import json,urllib.request,time
def est(w,xff=None,url='https://demande.vip-lift.com/api/estimate',origin=None):
    h={'Content-Type':'application/json'}
    if xff:h['X-Forwarded-For']=xff
    if origin:h['Origin']=origin
    req=urllib.request.Request(url,data=json.dumps({"levels":3,"emplacement":"INTERIEUR","produit":"HOME_LIFT_HYDRAULIQUE","wilaya":w}).encode(),headers=h)
    try:
        with urllib.request.urlopen(req,timeout=20) as r: return r.status
    except urllib.error.HTTPError as e: return e.code
    except Exception as e: return str(e)[:40]
out=[]
n=0
for i in range(120):
    c=est("Batna");n+=1
    if c==429: break
out.append(f"direct: 429 apres {n} appels" if c==429 else f"direct: pas de limite sur {n} ({c})")
out.append("meme IP + XFF 41.1.2.3: "+str(est("Batna","41.1.2.3")))
out.append("meme IP + XFF 41.9.9.9: "+str(est("Batna","41.9.9.9")))
time.sleep(2)
out.append("relais x3: "+",".join(str(est("Batna",url='https://vip-lift.com/devis-api.php?r=estimate',origin='https://vip-lift.com')) for _ in range(3)))
print(" | ".join(out))
