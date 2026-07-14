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

// Formulaire de contact : envoi direct vers domaines.trihent@gmail.com via FormSubmit,
// sans quitter la page. Le visiteur voit un message de confirmation.
const formulaire = document.getElementById('formulaire-contact');
if (formulaire) {
  const confirmation = document.getElementById('form-confirmation');
  formulaire.addEventListener('submit', async e => {
    e.preventDefault();
    const bouton = formulaire.querySelector('button[type="submit"]');
    const texteInitial = bouton.textContent;
    bouton.disabled = true;
    bouton.textContent = 'Envoi en cours…';
    try {
      const reponse = await fetch('https://formsubmit.co/ajax/domaines.trihent@gmail.com', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: new FormData(formulaire)
      });
      const resultat = await reponse.json().catch(() => ({}));
      if (reponse.ok && (resultat.success === true || resultat.success === 'true')) {
        formulaire.reset();
        if (confirmation) {
          confirmation.style.color = '';
          confirmation.textContent = 'Merci ! Votre demande a bien été envoyée. Nous vous répondrons très rapidement.';
          confirmation.style.display = 'block';
        }
      } else {
        throw new Error(resultat.message || 'Envoi impossible');
      }
    } catch (err) {
      if (confirmation) {
        confirmation.innerHTML = 'Une erreur est survenue lors de l\'envoi. Écrivez-nous directement à ' +
          '<a href="mailto:domaines.trihent@gmail.com">domaines.trihent@gmail.com</a> ' +
          'ou appelez-nous au 06 66 99 11 28.';
        confirmation.style.display = 'block';
      }
    } finally {
      bouton.disabled = false;
      bouton.textContent = texteInitial;
    }
  });
}
