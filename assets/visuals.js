import { animate, stagger } from 'animejs';
import * as THREE from 'three';

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
  if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
if (!reduceMotion) {
  const cards = document.querySelectorAll('.stat, .item, .auth-card');
  if (cards.length) animate(cards, { opacity: [0, 1], y: [12, 0], duration: 600, delay: stagger(65), ease: 'out(3)' });
}

const auth = document.querySelector('.auth-wrap');
if (auth && !reduceMotion) {
  try {
    const canvas = document.createElement('canvas');
    canvas.className = 'auth-art';
    canvas.setAttribute('aria-hidden', 'true');
    auth.prepend(canvas);
    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, 1, .1, 100);
    camera.position.z = 8;
    const geometry = new THREE.TorusGeometry(2.15, .18, 20, 96);
    const material = new THREE.MeshPhysicalMaterial({ color: 0x6caea4, metalness: .05, roughness: .27, transparent: true, opacity: .44, clearcoat: .85 });
    const ring = new THREE.Mesh(geometry, material);
    ring.rotation.x = .55;
    scene.add(ring);
    scene.add(new THREE.AmbientLight(0xffffff, 2.2));
    const light = new THREE.DirectionalLight(0xe2fff5, 2.4);
    light.position.set(-2, 3, 5);
    scene.add(light);
    function resize() {
      const size = Math.min(window.innerWidth * .7, 650);
      renderer.setSize(size, size);
      camera.aspect = 1;
      camera.updateProjectionMatrix();
    }
    resize();
    window.addEventListener('resize', resize, { passive: true });
    function frame() {
      if (document.visibilityState === 'visible') {
        ring.rotation.z += .0012;
        renderer.render(scene, camera);
      }
      requestAnimationFrame(frame);
    }
    frame();
  } catch { /* The interface remains usable without WebGL. */ }
}
