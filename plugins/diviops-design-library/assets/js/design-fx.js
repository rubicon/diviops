/**
 * Divi Design Library — Frontend Effects
 * Lightweight IntersectionObserver-based entrance animations.
 */
(function () {
  'use strict';

  function forEachNode(nodes, callback) {
    Array.prototype.forEach.call(nodes, callback);
  }

  // Wait for DOM ready.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    setupEntranceAnimations();
    injectGooeyFilter();
    setupImageReveals();
  }

  /**
   * Entrance animations via IntersectionObserver.
   * Add class "ddl-animate ddl-fade-up" (or ddl-fade-in, ddl-scale-in, etc.)
   * to any Divi module via CSS Classes in the VB.
   * The element starts hidden (opacity:0) and animates in when scrolled into view.
   */
  function setupEntranceAnimations() {
    var elements = document.querySelectorAll('.ddl-animate');
    if (!elements.length) return;

    // Fallback for old browsers: just show everything.
    if (!('IntersectionObserver' in window)) {
      forEachNode(elements, function (el) {
        el.classList.add('ddl-visible');
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('ddl-visible');
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.15,
      rootMargin: '0px 0px -40px 0px'
    });

    elements.forEach(function (el) {
      observer.observe(el);
    });
  }
  /**
   * Opt-in Image wipe reveal (#487).
   * Add class "ddl-image-reveal" to a Divi Image module.
   *
   * This function only ever ADDS a class that CSS animates. It never hides
   * anything: an image is fully visible before this runs, if it refuses, and
   * if the script never loads at all. Every early return below is therefore
   * safe by construction -- refusing costs an animation, not a visible image.
   *
   * The observer is attached AFTER load. Attaching it earlier lets native lazy
   * loading satisfy the intersection while the image is still blank, which
   * spends the reveal on nothing.
   */
  function setupImageReveals() {
    if (document.querySelector('#et-fb-app, .et-fb')) return;
    if (!('IntersectionObserver' in window) || !window.matchMedia ||
        !window.CSS || !window.CSS.supports ||
        !window.CSS.supports('clip-path', 'inset(0 0 0 0)')) return;

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (reducedMotion.matches) return;

    forEachNode(document.querySelectorAll('.et_pb_image.ddl-image-reveal'), function (module) {
      var img = module.querySelector('.et_pb_image_wrap img');
      // A nested image belongs to an inner module, not this one.
      if (!img || img.closest('.et_pb_image') !== module) return;

      var observer;
      var timer;
      var started = false;
      var finished = false;

      // Removing the active class is the ONLY way the clip ends. Every failure
      // path routes here, because a started-but-never-finished reveal leaves a
      // blank space on a published page.
      function cleanup() {
        finished = true;
        if (observer) observer.disconnect();
        window.clearTimeout(timer);
        img.removeEventListener('load', onLoad);
        img.removeEventListener('error', cleanup);
        img.removeEventListener('animationend', onAnimationDone);
        img.removeEventListener('animationcancel', onAnimationDone);
        img.classList.remove('ddl-image-reveal-active');
      }

      function onAnimationDone(event) {
        if (event.target === img && event.animationName === 'ddl-image-reveal') cleanup();
      }

      function onLoad() {
        if (finished || observer) return;
        img.removeEventListener('load', onLoad);
        // Loaded but zero-dimension: nothing to reveal.
        if (!img.naturalWidth) {
          cleanup();
          return;
        }

        observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (finished || started || !entry.isIntersecting) return;
            // Re-checked at fire time: the VB can open, and the motion
            // preference can change, between setup and intersection.
            if (reducedMotion.matches || document.querySelector('#et-fb-app, .et-fb')) {
              cleanup();
              return;
            }
            started = true;
            observer.disconnect();
            img.addEventListener('animationend', onAnimationDone);
            img.addEventListener('animationcancel', onAnimationDone);
            img.classList.add('ddl-image-reveal-active');
            // Last resort: if the CSS is absent or no animation event is
            // delivered, nothing else would ever remove the class.
            timer = window.setTimeout(cleanup, 750);
          });
        }, { threshold: 0 });
        observer.observe(img);
      }

      img.addEventListener('error', cleanup);
      if (img.complete) {
        onLoad();
      } else {
        img.addEventListener('load', onLoad);
      }
    });
  }

  /**
   * Inject SVG filter for gooey text morph effect.
   * Only added when .ddl-gooey-wrap is present on the page.
   */
  function injectGooeyFilter() {
    if (!document.querySelector('.ddl-gooey-wrap')) return;
    if (document.getElementById('ddl-gooey-filter')) return;
    if (!document.body) return;

    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    var filter = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
    var colorMatrix = document.createElementNS('http://www.w3.org/2000/svg', 'feColorMatrix');

    svg.setAttribute('style', 'position:absolute;height:0;width:0');
    svg.setAttribute('aria-hidden', 'true');

    filter.setAttribute('id', 'ddl-gooey-filter');
    colorMatrix.setAttribute('in', 'SourceGraphic');
    colorMatrix.setAttribute('type', 'matrix');
    colorMatrix.setAttribute('values', '1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 255 -140');

    filter.appendChild(colorMatrix);
    defs.appendChild(filter);
    svg.appendChild(defs);
    document.body.appendChild(svg);
  }
})();
