/* ---------- the bell: what is expired or about to expire ---------- */
// The count comes with every documents request (setExpiringBadge); the list is asked for when the bell is opened, and the
// count is refreshed every five minutes while the page stays open. Each document flags itself "tell me N days before".
const bell = {open: false, items: [], count: 0};

function updateBellBadge(n){
  bell.count = n;
  const badge = $("#bell-badge");
  badge.hidden = n === 0;
  badge.textContent = n > 99 ? "99+" : String(n);
  $("#bell").title = n === 0 ? "Reminders: nothing is due" : "Reminders: " + plural(n, "document") + " expired or coming up";
  $("#bell").setAttribute("aria-label", $("#bell").title);
}

async function loadReminders(){
  try{
    const data = await api("GET", "/api/reminders");
    bell.items = data.items || [];
    updateBellBadge(data.count || 0);
    if(bell.open) renderBell();
  }catch(e){
    if(e.status !== 401 && e.code !== "password_change_required" && bell.open) renderBell(errorWords(e));
  }
}

function closeBell(){
  const pop = document.getElementById("bell-pop");
  if(pop){ if(pop._off) pop._off(); pop.remove(); }
  bell.open = false;
  $("#bell").setAttribute("aria-expanded", "false");
}

function renderBell(problem){
  let pop = document.getElementById("bell-pop");
  if(!pop){
    pop = h("div", {class: "bell-pop", id: "bell-pop", role: "dialog", "aria-label": "Reminders"});
    document.body.append(pop);
    const away = event => { if(!pop.contains(event.target) && !$("#bell").contains(event.target)) closeBell(); };
    const key = event => { if(event.key === "Escape"){ closeBell(); $("#bell").focus(); } };
    pop._off = () => { document.removeEventListener("mousedown", away, true); document.removeEventListener("keydown", key, true); window.removeEventListener("resize", closeBell); };
    document.addEventListener("mousedown", away, true);
    document.addEventListener("keydown", key, true);
    window.addEventListener("resize", closeBell);
  }
  const box = $("#bell").getBoundingClientRect();
  pop.style.setProperty("top", box.bottom + 6 + "px");
  pop.style.setProperty("right", Math.max(8, window.innerWidth - box.right) + "px");

  const rows = bell.items.map(doc => h("button", {class: "bell-item", type: "button", onclick: () => { closeBell(); openDetail(doc.id); }},
    h("div", {class: "bi-top"}, h("span", {class: "bi-title"}, doc.title), expiryMark(doc)),
    h("div", {class: "bi-sub"}, h("span", {class: "tag"}, doc.code), h("span", {}, "Expires " + fmtDate(doc.expiresOn)), doc.folder ? h("span", {}, doc.folder) : null)));
  pop.replaceChildren(
    h("div", {class: "bell-head"}, h("b", {}, bell.count === 0 ? "Reminders" : plural(bell.count, "document") + " need attention"),
      h("p", {}, "Closest first. Each document tells you its own number of days ahead; change it in Edit details.")),
    problem ? h("div", {class: "bell-empty"}, problem)
      : rows.length === 0 ? h("div", {class: "bell-empty"}, "Nothing is expired or coming up. You are all caught up.")
      : h("div", {class: "bell-list"}, rows),
    h("div", {class: "bell-foot"},
      h("span", {}, bell.count > rows.length ? "Showing the closest " + rows.length + " of " + bell.count : ""),
      h("button", {class: "btn sm", type: "button", onclick: () => { closeBell(); showPage("expiring"); }}, "Open the Expiring list")));
}

$("#bell").addEventListener("click", () => {
  if(bell.open){ closeBell(); return; }
  bell.open = true;
  $("#bell").setAttribute("aria-expanded", "true");
  renderBell();
  loadReminders();   // fresh, while the person looks
});
setInterval(() => { if(currentUser && can("documentator.document.view")) loadReminders(); }, 5 * 60 * 1000);
