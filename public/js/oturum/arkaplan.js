/* Giriş sayfalarının parçacıklı arka planı (particles.js). Eski girişle aynı ayarlar. */
window.particlesJS('oturumArkaplan', {
  particles: {
    number: { value: 80, density: { enable: true, value_area: 800 } },
    color: { value: '#ffffff' },
    opacity: { value: 0.5 },
    size: { value: 5, random: true },
    line_linked: { enable: true, distance: 150, color: '#ffffff', opacity: 0.4, width: 1 },
    move: { enable: true, speed: 6 },
  },
  interactivity: {
    events: { onhover: { enable: true, mode: 'repulse' }, onclick: { enable: true, mode: 'push' }, resize: true },
  },
  retina_detect: true,
});
