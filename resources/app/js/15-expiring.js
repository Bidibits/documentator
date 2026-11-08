/* ---------- the Expiring panel ---------- */
// Documents that have expired or will soon, the soonest first, a page at a time from the server. The side bar's badge counts
// them (every documents request carries the count). Renew opens the same window as in the drawer.
const exp = {rows: [], page: 1, pages: 1, total: 0, per: 25, loaded: false};
let expToken = 0;

function clearExpiring(){
  exp.rows = []; exp.page = 1; exp.pages = 1; exp.total = 0; exp.loaded = false; expToken++;
  $("#exp-table").replaceChildren();
  $("#exp-pager").replaceChildren();
  $("#exp-count").textContent = "";
  setExpiringBadge(0);
}

function setExpiringBadge(n){
  const badge = $("#exp-badge");
  badge.hidden = n === 0;
  badge.textContent = n > 99 ? "99+" : String(n);
  badge.closest(".nav-item").title = n === 0 ? "Expiring" : "Expiring: " + plural(n, "document") + " expired or expiring soon";
}

async function loadExpiring(page){
  const token = ++expToken;
  if(page) exp.page = page;
  try{
    const data = await api("GET", "/api/documents?status=attention&sort=expiry&page=" + exp.page);
    if(token !== expToken) return;
    exp.rows = data.documents || [];
    exp.page = data.page; exp.pages = data.pages; exp.total = data.total; exp.per = data.per;
    exp.loaded = true;
    setExpiringBadge(data.expiringCount || 0);
    renderExpiring();
  }catch(e){
    if(token !== expToken || e.status === 401 || e.code === "password_change_required") return;
    $("#exp-table").replaceChildren(emptyBlock("Could not load the expiring documents", errorWords(e)));
    $("#exp-count").textContent = "";
    $("#exp-pager").replaceChildren();
  }
}

function renderExpiring(){
  $("#exp-count").textContent = exp.total === 0 ? "" : plural(exp.total, "document") + " expired or expiring soon";
  renderPager($("#exp-pager"), exp, page => loadExpiring(page));
  if(exp.rows.length === 0){
    $("#exp-table").replaceChildren(emptyBlock("Nothing is expiring", "No document has expired or runs out in the next 30 days. A document needs an expiry date to show up here."));
    return;
  }
  const th = label => h("th", {scope: "col"}, label);
  const body = exp.rows.map(doc => {
    const open = () => openDetail(doc.id);
    return h("tr", {tabindex: "0", onclick: open, onkeydown: e => { if(e.key === "Enter" || e.key === " "){ e.preventDefault(); open(); } }},
      h("td", {class: "mono"}, h("span", {class: "tag"}, doc.code)),
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, doc.title), doc.folder ? h("div", {class: "sub"}, doc.folder) : null),
      h("td", {class: "nowrap"}, doc.type || "-"),
      h("td", {class: "nowrap"}, doc.department || "-"),
      h("td", {}, expiryContent(doc)),
      h("td", {class: "nowrap"}, h("span", {class: "pill p-mute"}, "Ver " + doc.rev)),
      h("td", {class: "usr-acts"}, doc.can && doc.can.update
        ? h("button", {class: "btn sm", type: "button", onclick: async event => { event.stopPropagation(); await openRenewal(doc); }}, icon("upload"), "Renew") : null));
  });
  $("#exp-table").replaceChildren(h("table", {class: "rt"},
    h("thead", {}, h("tr", {}, th("Code"), th("Title"), th("Type"), th("Department"), th("Expires"), th("Ver"), th(""))),
    h("tbody", {}, body)));
}

// the renew window needs the whole document (the list row has no description): fetch it, then open the window
async function openRenewal(doc){
  try{
    const full = (await api("GET", "/api/documents/" + doc.id)).document;
    openNewVersion(full, true);
  }catch(e){ if(e.status !== 401) toast(errorWords(e), "warn"); }
}
