/* ---------- Section « Conseils » : derniers articles publiés depuis NextUp ----------
   Lus via conseils/?format=json (même site, mis en cache 5 min). Ajoutés après le guide
   mis en avant ; en cas d'indisponibilité, la section reste telle quelle. Sur mobile, la page
   est découpée en écrans par script.js : on la remet en place, on insère, puis on redécoupe
   (window.vlRelayout). */
(()=>{if(!document.querySelector('#blog .posts'))return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
fetch('conseils/?format=json',{headers:{Accept:'application/json'}}).then(r=>r.ok?r.json():[]).then(items=>{
  if(!Array.isArray(items)||!items.length)return;
  const html=items.map(p=>`<a class="post" href="${esc(p.url)}"><img src="${esc(p.image||'img/hero.webp')}" alt="${esc(p.title)}" loading="lazy" decoding="async" width="1024" height="768" onerror="this.onerror=null;this.src='img/hero.webp'"><h3>${esc(p.title)}</h3></a>`).join('');
  const insert=()=>{const g=document.querySelector('#blog .posts');if(!g)return;
    const feat=g.querySelector('.post.feat');
    if(feat&&feat.parentNode===g)feat.insertAdjacentHTML('afterend',html);else g.insertAdjacentHTML('afterbegin',html);
    if(typeof g._dots==='function')g._dots()};
  if(typeof window.vlRelayout==='function')window.vlRelayout(insert);else insert();
}).catch(()=>{});})();
