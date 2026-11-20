/* ---------- Liens avis Google et réseaux sociaux (à compléter) ---------- */
{const LIENS={
  avis:'https://search.google.com/local/writereview?placeid=ChIJn5FodZBPjhIR7HJIosSeKJQ', // ouvre directement la fenêtre « Écrire un avis » de la fiche Google
  showroom:'https://maps.google.com/?cid=10675957484055196396', // fiche Google Maps du showroom d'Alger
  facebook:'https://www.facebook.com/viplifttechnologie/', instagram:'https://www.instagram.com/vip.lift.algeria/', tiktok:'https://www.tiktok.com/@vip.lift', youtube:'https://youtu.be/qUVWjlsjn-8'  // coller ici les adresses des pages
};
for(const [k,u] of Object.entries(LIENS)){document.querySelectorAll('#lk-'+k+',[data-lk="'+k+'"]').forEach(a=>{if(u)a.href=u;else{a.removeAttribute('href');a.style.opacity='.45';a.title='Lien à ajouter'}})}}
