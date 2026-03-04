// ============================================================================
// CONFIGURATION GLOBALE
// ============================================================================
async function fetchJSON(url) {
  const res = await fetch(url);
  const json = await res.json();
  return json;
}

// Utilitaire pour formater une date ISO -> dd/mm/yyyy
function toFRDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric" });
}

// Correspond aux colonnes de Temps_Niveau et Temps_Week
const LEVEL_COLUMNS = [
  "-",
  "6eme",
  "5eme",
  "4eme",
  "3eme",
  "2nde",
  "1ere",
  "term",
  "term-pc",
];

let CURRENT_USER_ID = null;

// ============================================================================
// VARIABLES GLOBALES
// ============================================================================
let usersData = [];
let tempsNiveauData = [];
let tempsWeekData = [];
let progressionData = [];

let selectedLevel = "-";

// Index pour navigation semaines
let currentWeekIndex = 0;
let currentBarsStartIndex = 0;

// Tableau global des semaines complètes pour l'histogramme
let allCompleteWeeks = [];

// ============================================================================
// LOAD DATA (via /api/me.php + /api/temps_week.php)
// ============================================================================
async function loadData() {
  try {
    // Afficher le loader
    loaderEl.style.display = "flex";
    dashboardEl.style.display = "none";

    // Récupérer l'utilisateur via la session serveur
    const meRes = await fetch("/api/me.php");
    const meJson = await meRes.json();

    if (!meJson.ok) {
      throw new Error(meJson.error || "Session expirée. Recharge le lien signé.");
    }

    const me = meJson.me;
    CURRENT_USER_ID = me.user_id;

    // Construire les structures attendues par le dashboard
    const userRow = { user_id: me.user_id, username: me.username };
    const tempsRow = {
      user_id: me.user_id,
      date_maj: me.date_maj ?? null,
      "6eme": me.temps_6eme ?? 0,
      "5eme": me.temps_5eme ?? 0,
      "4eme": me.temps_4eme ?? 0,
      "3eme": me.temps_3eme ?? 0,
      "2nde": me.temps_2nde ?? 0,
      "1ere": me.temps_1ere ?? 0,
      term: me.temps_term ?? 0,
      "term-pc": me.temps_term_pc ?? 0,
    };
    const progRow = {
      user_id: me.user_id,
      date_maj: me.date_maj ?? null,
      "6eme": me.prog_6eme ?? 0,
      "5eme": me.prog_5eme ?? 0,
      "4eme": me.prog_4eme ?? 0,
      "3eme": me.prog_3eme ?? 0,
      "2nde": me.prog_2nde ?? 0,
      "1ere": me.prog_1ere ?? 0,
      term: me.prog_term ?? 0,
      "term-pc": me.prog_term_pc ?? 0,
    };

    usersData = [userRow];
    tempsNiveauData = [tempsRow];
    progressionData = [progRow];

    // Récupérer temps_week via session
    const weekRes = await fetch("/api/temps_week.php");
    const weekJson = await weekRes.json();
    if (!weekJson.ok) {
      throw new Error(weekJson.error || "Erreur /api/temps_week.php");
    }

    tempsWeekData = (weekJson.rows || []).map((r) => ({
      ...r,
      debute_le: r.debute_le,
      finit_le: r.finit_le,
    }));

    console.log("✅ Données chargées:", {
      user: CURRENT_USER_ID,
      weeks: tempsWeekData.length
    });

    // Init + UI
    initUser();
    initSelect();
    initIndexes();
    setupBarsNavigation();
    updateUI();

    await loadRegularity();

    loaderEl.style.display = "none";
    dashboardEl.style.display = "block";
  } catch (error) {
    console.error("❌ Erreur chargement données:", error);
    loaderEl.style.display = "none";
    alert("Impossible de charger les données. Vérifie que ta session est active (lien signé) et que les endpoints /api/me.php et /api/temps_week.php répondent.");
  }
}

// ============================================================================
// RÉGULARITÉ
// ============================================================================
async function loadRegularity() {
  try {
    console.log("loadRegularity() called");

    const res = await fetch("/api/regularity.php", { credentials: "include" });
    const json = await res.json();

    console.log("regularity json:", json);

    if (!json.ok) return;

    const activeDays = Number(json.active_days ?? 0);
    const tier = json.tier;
    const nextTier = json.next_tier;
    const daysToNext = Number(json.days_to_next ?? 0);

    // Période lisible
    const periodEl = document.getElementById("regularity-period");
    if (periodEl && json.month) {
      const [y, m] = String(json.month).split("-");
      const monthName = new Date(Number(y), Number(m) - 1, 1).toLocaleDateString(
        "fr-FR",
        { month: "long", year: "numeric" },
      );
      periodEl.textContent = `Ce mois-ci (${monthName})`;
    }

    // KPI
    const daysEl = document.getElementById("regularity-days");
    if (daysEl) {
      daysEl.textContent = `${activeDays} connexion${activeDays > 1 ? "s" : ""} ce mois-ci`;
    }

    // Barre de progression
    const maxDays = 25;
    const percent = Math.min(activeDays / maxDays, 1) * 100;

    const fill = document.getElementById("regularity-fill");
    if (fill) fill.style.width = percent + "%";

    // Stations
    const tiersOrder = ["bronze", "silver", "gold", "platinum", "diamond"];
    const TIER_LIMITS = { bronze: 3, silver: 6, gold: 12, platinum: 19, diamond: 25 };
    const TIER_LABELS = { bronze: "Bronze", silver: "Argent", gold: "Or", platinum: "Platine", diamond: "Diamant" };
    
    const stationsRoot = document.getElementById("regularity-stations");
    const stations = stationsRoot ? stationsRoot.querySelectorAll(".station") : [];

    stations.forEach((el) => {
      const t = el.dataset.tier;
      el.classList.remove("reached", "current", "locked");

      if (!tier) {
        el.classList.add("locked");
        return;
      }

      const idxT = tiersOrder.indexOf(t);
      const idxTier = tiersOrder.indexOf(tier);

      if (idxT === -1 || idxTier === -1) {
        el.classList.add("locked");
      } else if (idxT < idxTier) {
        el.classList.add("reached");
      } else if (idxT === idxTier) {
        el.classList.add("current");
      } else {
        el.classList.add("locked");
      }
    });

    // Tooltips stations
    stations.forEach((el) => {
      const t = el.dataset.tier;
      const tooltip = el.querySelector(".regularity-tooltip");
      if (!t || !tooltip) return;

      const label = TIER_LABELS[t] || t;
      const limit = TIER_LIMITS[t] || 0;

      let text = "";

      if (el.classList.contains("reached")) {
        text = `Badge ${label}. Déjà débloqué ce mois-ci ✔️`;
      } else if (el.classList.contains("current")) {
        text = `Badge ${label}. Ton niveau actuel 🌟`;
      } else {
        const remaining = Math.max(0, limit - activeDays);
        text = `Badge ${label}. Plus que ${remaining} connexion${remaining > 1 ? "s" : ""} pour l'atteindre 💪`;
      }

      tooltip.textContent = text;
    });

    // Message motivant
    const msgEl = document.getElementById("regularity-message");
    if (msgEl) {
      msgEl.style.display = "block";

      if (!tier) {
        msgEl.textContent = "Plus tu te connectes régulièrement, plus tu montes en niveau et débloques de nouveaux badges !";
      } else if (!nextTier) {
        msgEl.textContent = "💎 Niveau Diamant atteint ! Ta régularité est exceptionnelle, bravo !";
      } else {
        msgEl.textContent = `Encore ${daysToNext} jour${daysToNext > 1 ? "s" : ""} pour atteindre le niveau ${String(nextTier).toUpperCase()} 💪`;
      }
    }

    // Tooltip global au survol
    const thresholds = { bronze: 3, silver: 6, gold: 12, platinum: 19, diamond: 25 };
    const tooltip = document.getElementById("regularity-tooltip");

    function tierLabel(t) {
      const map = { bronze: "Bronze", silver: "Argent", gold: "Or", platinum: "Platine", diamond: "Diamant" };
      return map[t] || t;
    }

    if (tooltip && stations.length) {
      stations.forEach((el) => {
        const t = el.dataset.tier;
        const needed = thresholds[t];

        el.addEventListener("mousemove", (ev) => {
          if (!needed) return;

          const remaining = Math.max(0, needed - activeDays);
          let line1 = `${tierLabel(t)} — ${needed} jour${needed > 1 ? "s" : ""}`;
          let line2 = activeDays >= needed ? "✅ Palier atteint" : `Encore ${remaining} jour${remaining > 1 ? "s" : ""} pour l'atteindre 💪`;

          tooltip.innerHTML = `<strong>${line1}</strong><br>${line2}`;
          tooltip.style.display = "block";
          tooltip.style.left = ev.clientX + "px";
          tooltip.style.top = ev.clientY + "px";
        });

        el.addEventListener("mouseleave", () => {
          tooltip.style.display = "none";
        });
      });
    }

  } catch (e) {
    console.warn("Regularity not loaded", e);
  }
}

// ============================================================================
// UTILS TEMPS → format humain
// ============================================================================
function formatSeconds(seconds) {
  seconds = Number(seconds);

  if (isNaN(seconds) || seconds === 0) return "0 HEURE";
  if (seconds < 60) return `${seconds} SECONDES`;

  let totalMinutes = Math.floor(seconds / 60);

  if (totalMinutes < 60) {
    return `${totalMinutes} MINUTE${totalMinutes > 1 ? "S" : ""}`;
  }

  let hours = Math.floor(totalMinutes / 60);
  let remainingMinutes = totalMinutes % 60;

  if (remainingMinutes === 0) {
    return hours === 1 ? `1 HEURE` : `${hours} HEURES`;
  }

  return `${hours}H${remainingMinutes.toString().padStart(2, "0")}`;
}

// ============================================================================
// UTILS DATE → format humain
// ============================================================================
function getWeekDateRange(isoWeekStr) {
  try {
    const [year, week] = isoWeekStr.split("-W").map(Number);

    const jan4 = new Date(year, 0, 4);
    const jan4Day = jan4.getDay();

    const mondayWeek1 = new Date(jan4);
    mondayWeek1.setDate(jan4.getDate() - ((jan4Day + 6) % 7));

    const startDate = new Date(mondayWeek1);
    startDate.setDate(startDate.getDate() + (week - 1) * 7);

    const endDate = new Date(startDate);
    endDate.setDate(endDate.getDate() + 6);

    const fmt = (d) =>
      d.toLocaleDateString("fr-FR", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      });

    return {
      start: fmt(startDate),
      end: fmt(endDate),
    };
  } catch (error) {
    console.error("Erreur getWeekDateRange:", isoWeekStr, error);
    return { start: "—", end: "—" };
  }
}

// ============================================================================
// DOM REFERENCES
// ============================================================================
const usernameEl = document.getElementById("username");
const lastUpdatedEl = document.getElementById("last-updated");
const selectLevel = document.getElementById("niveau");
const globalHoursEl = document.getElementById("total-hours");
const globalScoreEl = document.getElementById("score");
const globalScoreCommentEl = document.getElementById("score-comment");
const weekHoursEl = document.getElementById("week-hours-display");
const weekRangeEl = document.getElementById("week-range");
const weekPrevBtn = document.getElementById("week-prev");
const weekNextBtn = document.getElementById("week-next");
const barsContainer = document.getElementById("bars");
const chartStartEl = document.getElementById("chart-start");
const chartEndEl = document.getElementById("chart-end");
const loaderEl = document.getElementById("loader");
const dashboardEl = document.getElementById("dashboard");
const selectLevelMessageEl = document.getElementById("select-level-message");
const dashboardContentEl = document.getElementById("dashboard-content");

// ============================================================================
// INIT USER
// ============================================================================
function initUser() {
  const niveauRow = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);
  const user = usersData.find((u) => u.user_id === CURRENT_USER_ID);

  usernameEl.textContent = user?.username ?? "Utilisateur";
  lastUpdatedEl.textContent = niveauRow?.date_maj ?? "-";
}

// ============================================================================
// SELECT LEVEL
// ============================================================================
function initSelect() {
  selectLevel.innerHTML = "";

  LEVEL_COLUMNS.forEach((level) => {
    const option = document.createElement("option");
    option.value = level;
    option.textContent = level === "-" ? "Sélectionnez un niveau" : level.toUpperCase();
    selectLevel.appendChild(option);
  });

  selectLevel.addEventListener("change", handleLevelChange);

  console.log("✅ Select initialisé avec", LEVEL_COLUMNS.length, "options");
}

// ============================================================================
// HANDLER CHANGEMENT DE NIVEAU
// ============================================================================
function handleLevelChange(event) {
  const newLevel = event.target.value;
  console.log("🔄 Changement de niveau:", selectedLevel, "→", newLevel);

  selectedLevel = newLevel;

  try {
    currentWeekIndex = 0;
    currentBarsStartIndex = 0;

    buildAllCompleteWeeks();
    updateUI();

    console.log("✅ Mise à jour réussie pour le niveau:", selectedLevel);
  } catch (error) {
    console.error("❌ Erreur lors du changement de niveau:", error);
    alert("Une erreur est survenue. Veuillez rafraîchir la page.");
  }
}

// ============================================================================
// INIT INDEXES POUR NAVIGATION
// ============================================================================
function initIndexes() {
  const rows = tempsWeekData.filter((r) => r.user_id === CURRENT_USER_ID);

  if (rows.length === 0) {
    currentWeekIndex = 0;
    currentBarsStartIndex = 0;
    return;
  }

  currentWeekIndex = rows.length - 1;
  buildAllCompleteWeeks();
  currentBarsStartIndex = Math.max(0, allCompleteWeeks.length - 4);
}

// ============================================================================
// CONSTRUIRE TOUTES LES SEMAINES COMPLÈTES
// ============================================================================
function buildAllCompleteWeeks() {
  allCompleteWeeks = [];

  if (selectedLevel === "-") {
    console.log("⚠️ Aucun niveau sélectionné");
    return;
  }

  try {
    let rows = tempsWeekData
      .filter((r) => r.user_id === CURRENT_USER_ID)
      .filter((r) => r.semaine && r.semaine.includes("-W"))
      .map((r) => ({ ...r }))
      .sort((a, b) => {
        // ✅ Trier par semaine ISO (format YYYY-Wxx)
        return a.semaine.localeCompare(b.semaine);
      });

    if (rows.length === 0) {
      console.log("⚠️ Aucune semaine disponible pour cet utilisateur");
      return;
    }

    console.log(`📊 Semaines disponibles: ${rows.length}`);
    console.log(`📅 Première: ${rows[0].semaine}, Dernière: ${rows[rows.length - 1].semaine}`);

    // ✅ On prend TOUTES les semaines disponibles sans essayer de combler les trous
    allCompleteWeeks = rows;

    currentWeekIndex = Math.max(0, allCompleteWeeks.length - 1);
    currentBarsStartIndex = Math.max(0, allCompleteWeeks.length - 4);

    console.log("✅ Semaines construites:", allCompleteWeeks.length);
  } catch (error) {
    console.error("❌ Erreur buildAllCompleteWeeks:", error);
    allCompleteWeeks = [];
  }
}

// ============================================================================
// SETUP NAVIGATION HISTOGRAMME
// ============================================================================
function setupBarsNavigation() {
  const prevArrow = document.getElementById("bar-prev");
  const nextArrow = document.getElementById("bar-next");

  if (!prevArrow || !nextArrow) {
    console.warn("⚠️ Boutons de navigation bars introuvables");
    return;
  }

  prevArrow.addEventListener("click", () => {
    if (currentBarsStartIndex > 0) {
      currentBarsStartIndex--;
      updateBars();
    }
  });

  nextArrow.addEventListener("click", () => {
    const maxIndex = Math.max(0, allCompleteWeeks.length - 4);
    if (currentBarsStartIndex < maxIndex) {
      currentBarsStartIndex++;
      updateBars();
    }
  });
}

// ============================================================================
// UI UPDATE GLOBAL
// ============================================================================
function updateUI() {
  console.log("🔄 Mise à jour UI pour le niveau:", selectedLevel);

  if (selectedLevel === "-") {
    selectLevelMessageEl.style.display = "block";
    dashboardContentEl.style.display = "none";
    return;
  }

  selectLevelMessageEl.style.display = "none";
  dashboardContentEl.style.display = "block";

  try {
    updateGlobalData();
    updateWeekCard();
    updateBars();
    updateRecapLists();
    console.log("✅ UI mise à jour");
  } catch (error) {
    console.error("❌ Erreur updateUI:", error);
  }
}

// ============================================================================
// GLOBAL TEMPS TOTAL + PROGRESSION
// ============================================================================
function updateGlobalData() {
  if (selectedLevel === "-") {
    globalHoursEl.textContent = "0";
    globalScoreEl.textContent = "0";
    globalScoreCommentEl.textContent = "Sélectionnez un niveau";
    return;
  }

  try {
    const row = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);

    if (!row) {
      console.warn("⚠️ Utilisateur non trouvé dans tempsNiveauData");
      globalHoursEl.textContent = "0";
    } else {
      const timeValue = row[selectedLevel];
      console.log(`Temps ${selectedLevel}:`, timeValue);
      globalHoursEl.textContent = formatSeconds(timeValue || 0);
    }

    const progressRow = progressionData.find((r) => r.user_id === CURRENT_USER_ID);

    if (!progressRow) {
      globalScoreEl.textContent = "0";
      globalScoreCommentEl.textContent = "Aucune donnée";
      return;
    }

    const score = Number(progressRow[selectedLevel] || 0);
    globalScoreEl.textContent = Math.round(score);

    if (score === 0) {
      globalScoreCommentEl.textContent = "Vous n'avez pas encore commencé";
    } else if (score < 25) {
      globalScoreCommentEl.textContent = "Bon début, continuez ! 💪";
    } else if (score < 50) {
      globalScoreCommentEl.textContent = "Vous progressez bien ! 🚀";
    } else if (score < 75) {
      globalScoreCommentEl.textContent = "Excellent travail ! 🌟";
    } else if (score < 100) {
      globalScoreCommentEl.textContent = "Presque au sommet ! 🏆";
    } else {
      globalScoreCommentEl.textContent = "Niveau complété ! 🎉";
    }
  } catch (error) {
    console.error("❌ Erreur updateGlobalData:", error);
  }
}

// ============================================================================
// SEMAINE CARD
// ============================================================================
function updateWeekCard() {
  if (selectedLevel === "-") {
    weekHoursEl.textContent = "0 Heure";
    weekRangeEl.textContent = "Aucun niveau sélectionné";
    weekPrevBtn.style.visibility = "hidden";
    weekNextBtn.style.visibility = "hidden";
    return;
  }

  if (!allCompleteWeeks.length || allCompleteWeeks.length === 0) {
    weekHoursEl.textContent = "0 Heure";
    weekRangeEl.textContent = "Aucune semaine disponible";
    weekPrevBtn.style.visibility = "hidden";
    weekNextBtn.style.visibility = "hidden";
    return;
  }

  try {
    const totalWeeks = allCompleteWeeks.length;

    if (currentWeekIndex < 0) currentWeekIndex = 0;
    if (currentWeekIndex >= totalWeeks) currentWeekIndex = totalWeeks - 1;

    const row = allCompleteWeeks[currentWeekIndex] || {};

    weekHoursEl.textContent = formatSeconds(row[selectedLevel] || 0);
    weekRangeEl.textContent = `Du ${row.debute_le ?? "-"} au ${row.finit_le ?? "-"}`;

    if (totalWeeks <= 1) {
      weekPrevBtn.style.visibility = "hidden";
      weekNextBtn.style.visibility = "hidden";
    } else {
      weekPrevBtn.style.visibility = currentWeekIndex > 0 ? "visible" : "hidden";
      weekNextBtn.style.visibility = currentWeekIndex < totalWeeks - 1 ? "visible" : "hidden";
    }

    weekPrevBtn.onclick = () => {
      if (currentWeekIndex > 0) {
        currentWeekIndex--;
        updateWeekCard();
      }
    };

    weekNextBtn.onclick = () => {
      if (currentWeekIndex < totalWeeks - 1) {
        currentWeekIndex++;
        updateWeekCard();
      }
    };
  } catch (error) {
    console.error("❌ Erreur updateWeekCard:", error);
  }
}

// ============================================================================
// HISTOGRAMME AVEC NAVIGATION
// ============================================================================
function updateBars() {
  barsContainer.innerHTML = "";

  const prevArrow = document.getElementById("bar-prev");
  const nextArrow = document.getElementById("bar-next");

  if (selectedLevel === "-" || allCompleteWeeks.length === 0) {
    chartStartEl.textContent = "—";
    chartEndEl.textContent = "—";
    if (prevArrow) prevArrow.style.visibility = "hidden";
    if (nextArrow) nextArrow.style.visibility = "hidden";
    return;
  }

  try {
    const totalWeeks = allCompleteWeeks.length;

    if (currentBarsStartIndex < 0) currentBarsStartIndex = 0;
    const maxIndex = Math.max(0, totalWeeks - 4);
    if (currentBarsStartIndex > maxIndex) currentBarsStartIndex = maxIndex;

    const windowWeeks = allCompleteWeeks.slice(
      currentBarsStartIndex,
      currentBarsStartIndex + 4,
    );

    // Gestion des flèches
    if (totalWeeks > 4 && prevArrow && nextArrow) {
      prevArrow.style.visibility = currentBarsStartIndex > 0 ? "visible" : "hidden";
      nextArrow.style.visibility = currentBarsStartIndex < maxIndex ? "visible" : "hidden";
    } else {
      if (prevArrow) prevArrow.style.visibility = "hidden";
      if (nextArrow) nextArrow.style.visibility = "hidden";
    }

    // Affichage des dates
    if (windowWeeks.length > 0) {
      chartStartEl.textContent = windowWeeks[0].debute_le;
      chartEndEl.textContent = windowWeeks[windowWeeks.length - 1].finit_le;
    }

    // Génération des barres
    const maxVal = Math.max(...windowWeeks.map((r) => Number(r[selectedLevel] || 0)), 1);
    const containerHeight = barsContainer.clientHeight || 190;
    const maxBarHeight = containerHeight * 0.65;
    const minBarHeight = 6;

    windowWeeks.forEach((row) => {
      const val = Number(row[selectedLevel] || 0);

      const bar = document.createElement("div");
      bar.className = "bar";

      let height = (val / maxVal) * maxBarHeight;
      bar.style.height = `${Math.max(height, minBarHeight)}px`;

      const label = document.createElement("div");
      label.className = "bar-label";
      label.textContent = formatSeconds(val);
      bar.appendChild(label);

      const tooltip = document.createElement("div");
      tooltip.className = "bar-tooltip";
      tooltip.innerHTML = `Du ${row.debute_le}<br>au ${row.finit_le}`;
      bar.appendChild(tooltip);

      barsContainer.appendChild(bar);
    });
  } catch (error) {
    console.error("❌ Erreur updateBars:", error);
  }
}

// ============================================================================
// RÉCAPITULATIF PAR NIVEAU
// ============================================================================
function updateRecapLists() {
  const progUl = document.getElementById("progression-list");
  const tempsUl = document.getElementById("temps-list");

  const progressRow = progressionData.find((r) => r.user_id === CURRENT_USER_ID);
  const tempsRow = tempsNiveauData.find((r) => r.user_id === CURRENT_USER_ID);

  if (progUl && progressRow) {
    progUl.innerHTML = LEVEL_COLUMNS.filter((l) => l !== "-")
      .map((lvl) => `<li>${lvl.toUpperCase()} : ${Math.round(Number(progressRow[lvl] || 0))}%</li>`)
      .join("");
  }

  if (tempsUl && tempsRow) {
    tempsUl.innerHTML = LEVEL_COLUMNS.filter((l) => l !== "-")
      .map((lvl) => `<li>${lvl.toUpperCase()} : ${formatSeconds(Number(tempsRow[lvl] || 0))}</li>`)
      .join("");
  }
}

// ============================================================================
// DÉCONNEXION
// ============================================================================
document.addEventListener('DOMContentLoaded', () => {
  const logoutBtn = document.getElementById('logout-btn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async () => {
      try {
        await fetch('/api/session_end.php');
      } catch (_) {}
      window.location.href = '/';
    });
  }
});

// ============================================================================
// START
// ============================================================================
loadData();