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

// Fetches the page again and morphs only the changed parts into the current page (like Turbo's page refresh).
async function refresh() {
  const response = await fetch(location.href, { headers: { Accept: "text/html" } }).catch(() => null);
  if (!response?.ok) {
    return;
  }
  const page = new DOMParser().parseFromString(await response.text(), "text/html");
  const idiomorph = await import(IDIOMORPH).catch(() => null);
  if (!idiomorph) {
    location.reload();
    return;
  }
  idiomorph.Idiomorph.morph(document.body, page.body, { morphStyle: "innerHTML" });
  document.title = page.title;
  formatTimes();
}

formatTimes();

// Refreshes the overview periodically, but only while the tab is visible.
const interval = Number(document.body.dataset.autorefresh);
if (interval > 0) {
  let last = Date.now();
  setInterval(() => {
    if (document.visibilityState === "visible" && Date.now() - last >= interval * 1000) {
      last = Date.now();
      refresh();
    }
  }, 5000);
}

document.addEventListener("change", (event) => {
  if (event.target.matches("select[data-autosubmit]")) {
    event.target.form.submit();
  }
});
