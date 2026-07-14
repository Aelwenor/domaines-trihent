// Navigation : fond au défilement
const entete = document.getElementById('entete');
window.addEventListener('scroll', () => {
  entete.classList.toggle('scrolled', window.scrollY > 60);
});

// Menu mobile
function basculerMenu() {
  entete.classList.toggle('menu-ouvert');
}

// FAQ accordéon
function basculerFaq(bouton) {
  const item = bouton.parentElement;
  const reponse = item.querySelector('.faq-reponse');
  const dejaOuvert = item.classList.contains('ouvert');
  document.querySelectorAll('.faq-item.ouvert').forEach(autre => {
    autre.classList.remove('ouvert');
    autre.querySelector('.faq-reponse').style.maxHeight = null;
  });
  if (!dejaOuvert) {
    item.classList.add('ouvert');
    reponse.style.maxHeight = reponse.scrollHeight + 'px';
  }
}

// Apparition au défilement
const observateur = new IntersectionObserver(entrees => {
  entrees.forEach(e => {
    if (e.isIntersecting) {
      e.target.classList.add('visible');
      observateur.unobserve(e.target);
    }
  });
}, { threshold: 0.15 });
document.querySelectorAll('.reveal').forEach(el => observateur.observe(el));

// Compteurs animés
const obsCompteurs = new IntersectionObserver(entrees => {
  entrees.forEach(e => {
    if (!e.isIntersecting) return;
    const el = e.target;
    const cible = parseFloat(el.dataset.cible);
    const decimales = parseInt(el.dataset.decimale || '0');
    const duree = 1600;
    const debut = performance.now();
    function tic(t) {
      const p = Math.min((t - debut) / duree, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = (cible * eased).toFixed(decimales);
      if (p < 1) requestAnimationFrame(tic);
    }
    requestAnimationFrame(tic);
    obsCompteurs.unobserve(el);
  });
}, { threshold: 0.6 });
document.querySelectorAll('.compteur').forEach(el => obsCompteurs.observe(el));

// Galerie des biens : la vignette cliquée devient la photo principale
function changerPhoto(vignette) {
  const bien = vignette.closest('.bien');
  const principale = bien.querySelector('.visuel img');
  principale.src = vignette.dataset.grand || vignette.src;
  principale.alt = vignette.alt;
  bien.querySelectorAll('.vignettes img').forEach(v => v.classList.remove('actif'));
  vignette.classList.add('actif');
}

// Formulaire de contact : l'envoi est géré nativement par le navigateur
// (le <form> poste directement vers FormSubmit, qui transfère la demande
// vers domaines.trihent@gmail.com puis redirige vers merci.html).
// Aucun JavaScript n'est nécessaire ici — c'est la méthode la plus fiable.
