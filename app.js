const IDIOMORPH = "https://cdn.jsdelivr.net/npm/idiomorph@0.8.0/dist/idiomorph.esm.js";

// Shows all <time datetime> elements in the viewer's local time zone.
const formats = {
  time: { timeStyle: "short" },
  weekday: { weekday: "short", day: "numeric" },
  date: { month: "short", day: "numeric" },
  default: { dateStyle: "medium", timeStyle: "short" },
};

function formatTimes() {
  for (const el of document.querySelectorAll("time[datetime]")) {
    const date = new Date(el.getAttribute("datetime"));
    if (!Number.isNaN(date.valueOf())) {
      el.title = el.textContent;
      el.textContent = new Intl.DateTimeFormat(undefined, formats[el.dataset.format] ?? formats.default).format(date);
    }
  }
}

async function fetchPage(url) {
  const response = await fetch(url, { headers: { Accept: "text/html" } }).catch(() => null);
  if (!response?.ok) {
    return null;
  }
  return { url: response.url, page: new DOMParser().parseFromString(await response.text(), "text/html") };
}

// Morphs only the changed parts of the fetched page into the current page (like Turbo).
async function render(page) {
  const idiomorph = await import(IDIOMORPH).catch(() => null);
  if (!idiomorph) {
    return false;
  }
  idiomorph.Idiomorph.morph(document.body, page.body);
  document.title = page.title;
  formatTimes();
  return true;
}

async function refresh() {
  const result = await fetchPage(location.href);
  if (result && !(await render(result.page))) {
    location.reload();
  }
}

async function navigate(url) {
  const samePage = new URL(url).searchParams.get("site") === new URLSearchParams(location.search).get("site");
  document.documentElement.ariaBusy = "true";
  const result = await fetchPage(url);
  document.documentElement.ariaBusy = null;
  if (!result) {
    location.href = url;
    return;
  }
  history.pushState(null, "", result.url);
  if (!(await render(result.page))) {
    location.reload();
    return;
  }
  if (!samePage) {
    scrollTo(0, 0);
  }
}

formatTimes();

// Refreshes pages with data-autorefresh periodically, but only while the tab is visible.
let lastRefresh = Date.now();
setInterval(() => {
  const interval = Number(document.body.dataset.autorefresh);
  if (interval > 0 && document.visibilityState === "visible" && Date.now() - lastRefresh >= interval * 1000) {
    lastRefresh = Date.now();
    refresh();
  }
}, 5000);

document.addEventListener("click", (event) => {
  const link = event.target.closest("a[href]");
  const modified = event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
  if (!link || modified || event.defaultPrevented || link.target || link.hasAttribute("download")) {
    return;
  }
  if (link.origin !== location.origin || link.pathname !== location.pathname) {
    return;
  }
  event.preventDefault();
  navigate(link.href);
});

document.addEventListener("change", (event) => {
  if (event.target.matches("select[data-autosubmit]")) {
    const form = event.target.form;
    const url = new URL(form.action);
    url.search = new URLSearchParams(new FormData(form)).toString();
    navigate(url.href);
  }
});

addEventListener("popstate", refresh);
