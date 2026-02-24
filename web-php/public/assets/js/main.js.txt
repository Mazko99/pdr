(function () {
  // Smooth scroll for anchors
  document.addEventListener("click", (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (!a) return;

    const href = a.getAttribute("href");
    if (!href || href === "#") return;

    const el = document.querySelector(href);
    if (!el) return;

    e.preventDefault();
    el.scrollIntoView({ behavior: "smooth", block: "start" });

    // close mobile
    const mobile = document.querySelector("[data-mobile]");
    mobile?.classList.remove("is-open");
    document.documentElement.classList.remove("is-menu-open");
  });

  // Burger / Mobile menu
  const burger = document.querySelector("[data-burger]");
  const mobile = document.querySelector("[data-mobile]");
  const mobileClose = document.querySelector("[data-mobile-close]");

  const closeMobile = () => {
    mobile?.classList.remove("is-open");
    document.documentElement.classList.remove("is-menu-open");
  };

  burger?.addEventListener("click", () => {
    mobile?.classList.toggle("is-open");
    document.documentElement.classList.toggle("is-menu-open", mobile?.classList.contains("is-open"));
  });

  mobileClose?.addEventListener("click", () => closeMobile());

  // close mobile by click outside (when opened)
  document.addEventListener("click", (e) => {
    if (!mobile?.classList.contains("is-open")) return;
    const inMenu = e.target.closest("[data-mobile]");
    const inBurger = e.target.closest("[data-burger]");
    if (!inMenu && !inBurger) closeMobile();
  });

  // User menu (account dropdown)
  const userBtn = document.querySelector("[data-user-menu-btn]");
  const userMenu = document.querySelector("[data-user-menu]");
  const toggleUser = () => userMenu?.classList.toggle("is-open");
  const closeUser = () => userMenu?.classList.remove("is-open");

  userBtn?.addEventListener("click", (e) => {
    e.preventDefault();
    e.stopPropagation();
    toggleUser();
  });

  document.addEventListener("click", (e) => {
    if (!userMenu) return;
    if (!userMenu.classList.contains("is-open")) return;
    const inBtn = e.target.closest("[data-user-menu-btn]");
    const inMenu = e.target.closest("[data-user-menu]");
    if (!inBtn && !inMenu) closeUser();
  });

  // FAQ accordion
  const items = Array.from(document.querySelectorAll("[data-faq-item]"));
  items.forEach((btn) => {
    btn.addEventListener("click", () => {
      const panel = btn.nextElementSibling;
      if (!panel || !panel.classList.contains("faq__panel")) return;

      const isOpen = panel.classList.contains("is-open");

      document.querySelectorAll(".faq__panel.is-open").forEach((p) => p.classList.remove("is-open"));
      if (!isOpen) panel.classList.add("is-open");
    });
  });

  // Scroll to top visibility
  const topBtn = document.querySelector("[data-scroll-top]");
  const onScroll = () => {
    if (!topBtn) return;
    if (window.scrollY < 450) topBtn.classList.add("is-hidden");
    else topBtn.classList.remove("is-hidden");
  };
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  topBtn?.addEventListener("click", () => {
    window.scrollTo({ top: 0, behavior: "smooth" });
  });
})();
document.addEventListener("DOMContentLoaded", function(){

    const circle = document.querySelector(".progress-ring-fill");

    if(!circle) return;

    const percent = parseInt(circle.dataset.percent);

    const radius = 70;

    const circumference = 2 * Math.PI * radius;

    const offset = circumference - (percent / 100) * circumference;

    circle.style.strokeDashoffset = offset;

});