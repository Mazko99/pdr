(() => {
  // ====== 1) Hover bold for exam steps (also keep "active" on hover) ======
  const examSteps = document.getElementById("examSteps");
  if (examSteps) {
    const items = [...examSteps.querySelectorAll(".stepsList__item")];
    items.forEach((li) => {
      li.addEventListener("mouseenter", () => {
        items.forEach((x) => x.classList.remove("is-active"));
        li.classList.add("is-active");
      });
      li.addEventListener("mouseleave", () => {
        // keep last hovered as active (same vibe as screenshot)
      });
    });
  }

  // ====== 2) "To top" button ======
  const toTopBtn = document.getElementById("toTopBtn");
  if (toTopBtn) {
    toTopBtn.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  // ====== 3) Sticky stepper (locks scroll inside process section) ======
  const stepper = document.getElementById("processStepper");
  if (!stepper) return;

  const slides = [...stepper.querySelectorAll(".slide")];
  const navBtns = [...document.querySelectorAll("#processNav .processNav__btn")];
  const dots = [...stepper.querySelectorAll(".stageDots__dot")];

  let index = 0;
  let locked = false;
  let inView = false;
  let wheelBusy = false;

  const setIndex = (next) => {
    index = Math.max(0, Math.min(slides.length - 1, next));
    slides.forEach((s, i) => s.classList.toggle("is-active", i === index));
    navBtns.forEach((b, i) => b.classList.toggle("is-active", i === index));
    dots.forEach((d, i) => d.classList.toggle("is-on", i === index));
  };

  navBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      const go = Number(btn.getAttribute("data-go") || "0");
      setIndex(go);
    });
  });

  // Observe when stepper is in center-ish of viewport
  const io = new IntersectionObserver(
    (entries) => {
      for (const e of entries) {
        inView = e.isIntersecting;
        // if user scrolls away, unlock
        if (!inView) locked = false;
      }
    },
    { threshold: 0.55 }
  );
  io.observe(stepper);

  const lockIfNeeded = () => {
    // lock only while section is in view and not at edges
    if (!inView) return false;
    return true;
  };

  const onWheel = (ev) => {
    if (!lockIfNeeded()) return;

    // when at first slide and user scrolls up -> allow normal scroll (unlock)
    if (index === 0 && ev.deltaY < 0) {
      locked = false;
      return;
    }
    // when at last slide and user scrolls down -> allow normal scroll (unlock)
    if (index === slides.length - 1 && ev.deltaY > 0) {
      locked = false;
      return;
    }

    // otherwise lock and consume scroll
    locked = true;
    ev.preventDefault();

    if (wheelBusy) return;
    wheelBusy = true;

    const dir = ev.deltaY > 0 ? 1 : -1;
    setIndex(index + dir);

    setTimeout(() => {
      wheelBusy = false;
    }, 240);
  };

  // Important: passive:false to allow preventDefault
  window.addEventListener("wheel", onWheel, { passive: false });

  // Touch support (simple)
  let touchY = null;
  window.addEventListener(
    "touchstart",
    (e) => {
      if (!lockIfNeeded()) return;
      touchY = e.touches[0]?.clientY ?? null;
    },
    { passive: true }
  );

  window.addEventListener(
    "touchmove",
    (e) => {
      if (!lockIfNeeded()) return;
      if (touchY == null) return;

      const y = e.touches[0]?.clientY ?? touchY;
      const dy = touchY - y;

      // same edge unlock rules
      if (index === 0 && dy < -8) {
        locked = false;
        touchY = y;
        return;
      }
      if (index === slides.length - 1 && dy > 8) {
        locked = false;
        touchY = y;
        return;
      }

      locked = true;
      e.preventDefault();

      if (Math.abs(dy) > 14) {
        setIndex(index + (dy > 0 ? 1 : -1));
        touchY = y;
      }
    },
    { passive: false }
  );

  // Start state
  setIndex(0);
})();
