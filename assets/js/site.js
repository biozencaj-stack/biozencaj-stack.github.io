/* IzradaWebSajta.co — site interactivity v2 (vanilla JS, no framework). */
(function () {
  "use strict";

  var isEN = /^\/en\//.test(location.pathname);

  /* ---------- Header: sakrij pri skrolu nadole, prikaži nagore ---------- */
  var nav = document.querySelector(".nav");
  var lastY = window.scrollY;
  var burger = document.querySelector(".nav-burger");
  var panel = document.getElementById("m-nav");
  if (nav) {
    window.addEventListener("scroll", function () {
      var y = window.scrollY;
      var menuOpen = panel && panel.classList.contains("open");
      if (!menuOpen) {
        if (y > lastY + 6 && y > 140) nav.classList.add("hide");
        else if (y < lastY - 6 || y <= 140) nav.classList.remove("hide");
      }
      lastY = y;
    }, { passive: true });
  }

  /* ---------- Mobile menu ---------- */
  if (burger && panel) {
    burger.addEventListener("click", function () {
      var open = panel.classList.toggle("open");
      burger.setAttribute("aria-expanded", open ? "true" : "false");
    });
    panel.addEventListener("click", function (e) {
      if (e.target.tagName === "A") {
        panel.classList.remove("open");
        burger.setAttribute("aria-expanded", "false");
      }
    });
  }

  var noMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Stepenasto otkrivanje unutar grid-ova ---------- */
  document.querySelectorAll(".grid-2,.grid-3,.grid-4,.logo-grid").forEach(function (g) {
    Array.prototype.forEach.call(g.children, function (c, i) {
      if (c.classList.contains("reveal")) c.style.transitionDelay = (i % 12) * 70 + "ms";
    });
  });

  /* ---------- Brojači u statistikama ---------- */
  var stats = document.querySelectorAll(".stat b");
  if (stats.length && "IntersectionObserver" in window && !noMotion) {
    var sio = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        sio.unobserve(en.target);
        var m = en.target.textContent.match(/^(\d+)(.*)$/);
        if (!m) return;
        var target = +m[1], suffix = m[2], t0 = null;
        function tick(t) {
          if (!t0) t0 = t;
          var p = Math.min((t - t0) / 1300, 1);
          var eased = 1 - Math.pow(1 - p, 3);
          en.target.textContent = Math.round(target * eased) + suffix;
          if (p < 1) requestAnimationFrame(tick);
        }
        en.target.textContent = "0" + suffix;
        requestAnimationFrame(tick);
        setTimeout(function () { en.target.textContent = target + suffix; }, 1500);
      });
    }, { threshold: 0.6 });
    stats.forEach(function (s) { sio.observe(s); });
  }

  /* ---------- 3D naginjanje pločica (samo miš, bez reduced-motion) ---------- */
  if (window.matchMedia("(pointer: fine)").matches && !noMotion) {
    document.querySelectorAll(".tile, .shot-card").forEach(function (el) {
      el.addEventListener("mousemove", function (e) {
        var r = el.getBoundingClientRect();
        var rx = ((e.clientY - r.top) / r.height - 0.5) * -5;
        var ry = ((e.clientX - r.left) / r.width - 0.5) * 5;
        el.style.transform = "perspective(700px) rotateX(" + rx.toFixed(2) + "deg) rotateY(" + ry.toFixed(2) + "deg) translateY(-4px)";
      });
      el.addEventListener("mouseleave", function () {
        el.style.transform = "";
      });
    });
  }

  /* ---------- Orb: prati skrol i iskače u CTA ---------- */
  var orb = document.querySelector(".orb");
  if (orb && !noMotion) {
    var orbY = 0, orbT = 0, orbLast = window.scrollY, popped = false;
    window.addEventListener("scroll", function () {
      var y = window.scrollY;
      orbT = Math.max(-16, Math.min(16, (y - orbLast) * 0.35));
      orbLast = y;
      var trigger = window.innerHeight * 1.4;
      if (!popped && y > trigger) { popped = true; orb.classList.add("pop"); }
      else if (popped && y < trigger - 300) { popped = false; orb.classList.remove("pop"); }
    }, { passive: true });
    (function orbLoop() {
      orbY += (orbT - orbY) * 0.12;
      orbT *= 0.92;
      orb.style.transform = "translateY(" + orbY.toFixed(2) + "px)";
      requestAnimationFrame(orbLoop);
    })();
  }

  /* ---------- Reveal on scroll ---------- */
  var revealed = document.querySelectorAll(".reveal");
  if (revealed.length && "IntersectionObserver" in window &&
      !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add("in"); io.unobserve(en.target); }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    revealed.forEach(function (el) { io.observe(el); });
  } else {
    revealed.forEach(function (el) { el.classList.add("in"); });
  }

  /* ---------- Contact form ---------- */
  var form = document.getElementById("contact-form");
  if (form) {
    var submitBtn = form.querySelector('button[type="submit"]');
    var consent = document.getElementById("consent");
    var status = document.getElementById("form-status");
    var sync = function () { submitBtn.disabled = consent ? !consent.checked : false; };
    if (consent) { consent.addEventListener("change", sync); sync(); }

    var T = isEN
      ? { sending: "Sending…", ok: "Your message has been sent. Thank you!", err: "Failed to send. Please try again.", net: "Network error. Please try again." }
      : { sending: "Slanje…", ok: "Poruka je uspešno poslata. Hvala!", err: "Greška pri slanju. Pokušajte ponovo.", net: "Greška u mreži. Pokušajte ponovo." };

    var val = function (id) { var el = document.getElementById(id); return el ? el.value.trim() : ""; };

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (val("website")) { status.textContent = T.ok; return; } /* honeypot */
      status.style.color = "inherit";
      status.textContent = T.sending;
      submitBtn.disabled = true;
      fetch("/api/contact.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: val("name"), email: val("email"), phone: val("phone"),
          service: val("service"), message: val("message"),
          company: val("company"), budget: val("budget"),
          deadline: val("deadline"), siteurl: val("siteurl"), source: val("source"),
          locale: isEN ? "en" : "sr", website: ""
        })
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
        .then(function (res) {
          if (res.ok && res.d && res.d.success) {
            status.style.color = "#157f3d"; status.textContent = res.d.message || T.ok; form.reset();
          } else {
            status.style.color = "#c2281d";
            status.textContent = (res.d && (res.d.error || (res.d.details && res.d.details[0]))) || T.err;
          }
        })
        .catch(function () { status.style.color = "#c2281d"; status.textContent = T.net; })
        .finally(function () { if (consent) sync(); else submitBtn.disabled = false; });
    });
  }
})();
