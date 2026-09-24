// Shows all <time datetime> elements in the viewer's local time zone.
const formatter = new Intl.DateTimeFormat(undefined, {
  dateStyle: "medium",
  timeStyle: "short",
});

for (const el of document.querySelectorAll("time[datetime]")) {
  const date = new Date(el.getAttribute("datetime"));
  if (!Number.isNaN(date.valueOf())) {
    el.title = el.textContent;
    el.textContent = formatter.format(date);
  }
}

// Reloads the overview periodically, but only while the tab is visible.
const refresh = Number(document.body.dataset.autorefresh);
if (refresh > 0) {
  let last = Date.now();
  setInterval(() => {
    if (document.visibilityState === "visible" && Date.now() - last >= refresh * 1000) {
      last = Date.now();
      location.reload();
    }
  }, 5000);
}

for (const select of document.querySelectorAll("select[data-autosubmit]")) {
  select.addEventListener("change", () => select.form.submit());
}
