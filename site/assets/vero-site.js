/* VERO site — interações (reveal, contadores, abas, nav, espinha, parallax) */
(function () {
  "use strict";
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* Nav: sombra ao rolar + menu mobile */
  var nav = document.querySelector(".nav");
  function onScrollNav() { if (nav) nav.classList.toggle("scrolled", window.scrollY > 8); }
  window.addEventListener("scroll", onScrollNav, { passive: true });
  onScrollNav();

  var burger = document.querySelector(".nav-burger");
  var links = document.querySelector(".nav-links");
  if (burger && links) {
    burger.addEventListener("click", function () {
      var open = links.classList.toggle("open");
      burger.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  /* Reveal on scroll */
  var revealEls = document.querySelectorAll(".rv");
  if ("IntersectionObserver" in window && !reduced) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -40px 0px" });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("in"); });
  }

  /* Contadores animados */
  function animateCounter(el) {
    var target = parseFloat(el.getAttribute("data-count"));
    var decimals = parseInt(el.getAttribute("data-decimals") || "0", 10);
    var dur = 1600, start = null;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = target * eased;
      el.textContent = val.toLocaleString("pt-BR", {
        minimumFractionDigits: decimals, maximumFractionDigits: decimals
      });
      if (p < 1) requestAnimationFrame(step);
    }
    if (reduced) {
      el.textContent = target.toLocaleString("pt-BR", { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    } else {
      requestAnimationFrame(step);
    }
  }
  var counters = document.querySelectorAll("[data-count]");
  if (counters.length && "IntersectionObserver" in window) {
    var ioC = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { animateCounter(e.target); ioC.unobserve(e.target); }
      });
    }, { threshold: 0.5 });
    counters.forEach(function (el) { ioC.observe(el); });
  } else {
    counters.forEach(animateCounter);
  }

  /* Espinha: acende os passos em sequência quando visível */
  var spine = document.querySelector(".spine-wrap");
  if (spine && "IntersectionObserver" in window) {
    var ioS = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        ioS.unobserve(e.target);
        var items = spine.querySelectorAll(".spine-list li");
        var bar = spine.querySelector(".spine-track i");
        if (bar) bar.style.width = "100%";
        items.forEach(function (li, i) {
          setTimeout(function () { li.classList.add("active"); }, reduced ? 0 : 140 * i);
        });
      });
    }, { threshold: 0.35 });
    ioS.observe(spine);
  }

  /* Abas (gestão por área) */
  document.querySelectorAll("[data-tabs]").forEach(function (wrap) {
    var btns = wrap.querySelectorAll(".tab-btn");
    var panels = wrap.querySelectorAll(".tab-panel");
    btns.forEach(function (btn) {
      btn.addEventListener("click", function () {
        btns.forEach(function (b) { b.setAttribute("aria-selected", "false"); });
        panels.forEach(function (p) { p.classList.remove("active"); });
        btn.setAttribute("aria-selected", "true");
        var panel = wrap.querySelector("#" + btn.getAttribute("aria-controls"));
        if (panel) panel.classList.add("active");
      });
    });
  });

  /* Parallax discreto no mockup do hero */
  var hero = document.querySelector(".hero");
  var floatEl = document.querySelector("[data-parallax]");
  if (hero && floatEl && !reduced && window.matchMedia("(pointer: fine)").matches) {
    hero.addEventListener("mousemove", function (ev) {
      var r = hero.getBoundingClientRect();
      var x = (ev.clientX - r.left) / r.width - 0.5;
      var y = (ev.clientY - r.top) / r.height - 0.5;
      floatEl.style.transform = "perspective(1100px) rotateY(" + (x * 4) + "deg) rotateX(" + (-y * 3.2) + "deg) translateY(" + (y * -6) + "px)";
    });
    hero.addEventListener("mouseleave", function () { floatEl.style.transform = ""; });
  }

  /* Partículas do hero */
  var pWrap = document.querySelector(".particles");
  if (pWrap && !reduced) {
    for (var i = 0; i < 22; i++) {
      var s = document.createElement("span");
      s.style.left = (Math.random() * 100) + "%";
      s.style.bottom = "-4px";
      s.style.animationDuration = (9 + Math.random() * 14) + "s";
      s.style.animationDelay = (-Math.random() * 18) + "s";
      s.style.opacity = String(0.25 + Math.random() * 0.5);
      pWrap.appendChild(s);
    }
  }

  /* Formulário de demonstração: POST real p/ enviar.php; aqui só o aviso de erro */
  var demoErro = document.getElementById("form-erro");
  if (demoErro && new URLSearchParams(window.location.search).has("erro")) {
    demoErro.hidden = false;
    demoErro.scrollIntoView({ block: "center", behavior: "smooth" });
  }

  /* Ano corrente no footer */
  document.querySelectorAll("[data-year]").forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });
})();
