import json,urllib.request
W=["Adrar","Aïn Defla","Aïn Témouchent","Alger","Annaba","Batna","Bechar","Béjaïa","Béni Abbès","Biskra","Blida","Bordj Badj Mokhtar","Bordj Bou Arréridj","Bouira","Boumerdès","Chlef","Constantine","Djanet","Djelfa","El Bayadh","El Meniaa","El Mghaier","El Oued","El Tarf","Ghardaïa","Guelma","HASSI MESSAOUD","Illizi","In Guezzam","In Salah","Jijel","Khenchela","Laghouat","Mascara","Médéa","Mila","M'Sila","Mostaganem","Naâma","Oran","Ouargla","Ouled Djellal","Oum El Bouaghi","Relizane","Saïda","Sétif","Sidi Bel Abbès","Skikda","Souk Ahras","Tamanrasset","Tébessa","Tiaret","Timimoun","Tindouf","Tipaza","Tissemsilt","Tizi Ouzou","Tlemcen","Touggourt"]
bad=[];ok=0
def est(w,prod,niv):
    req=urllib.request.Request('https://demande.vip-lift.com/api/estimate',data=json.dumps({"levels":niv,"emplacement":"INTERIEUR","produit":prod,"wilaya":w}).encode(),headers={'Content-Type':'application/json'})
    try:
        with urllib.request.urlopen(req,timeout=20) as r: return r.status,json.loads(r.read())
    except urllib.error.HTTPError as e: return e.code,e.read()[:120]
    except Exception as e: return 0,str(e)[:80]
for w in W:
    c,j=est(w,"HOME_LIFT_HYDRAULIQUE",3)
    if c==200 and isinstance(j,dict) and j.get("success"): ok+=1
    else: bad.append(f"{w}:{c}:{str(j)[:60]}")
for prod in ["HOME_LIFT_HYDRAULIQUE","HOME_LIFT_AVEC_CHARPENTE","ASCENSEUR_STANDARD"]:
    for niv in [2,3,5]:
        c,j=est("Batna",prod,niv); bad.append(f"Batna {prod} {niv!r}:{c}:{str(j)[:70]}")
print("OK",ok,"| "+" | ".join(bad))
