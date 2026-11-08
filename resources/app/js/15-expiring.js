/* ---------- the Expiring panel ---------- */
// Documents that have expired or will soon (the server marks each one), the soonest first, from the same list the Documents
// panel loads. The side bar's badge counts them. Renew opens the same window as in the drawer.
function expiringDocs(){
  return docs.all.filter(d => d.expiryStatus === "expiring" || d.expiryStatus === "expired")
    .sort((a, b) => a.expiresOn < b.expiresOn ? -1 : a.expiresOn > b.expiresOn ? 1 : a.code.localeCompare(b.code));
}

function clearExpiring(){
  $("#exp-table").replaceChildren();
  $("#exp-count").textContent = "";
  setExpiringBadge(0);
}

function setExpiringBadge(n){
  const badge = $("#exp-badge");
  badge.hidden = n === 0;
  badge.textContent = n > 99 ? "99+" : String(n);
  badge.closest(".nav-item").title = n === 0 ? "Expiring" : "Expiring: " + plural(n, "document") + " expired or expiring soon";
}

function renderExpiring(){
  const rows = expiringDocs();
  setExpiringBadge(rows.length);
  const expired = rows.filter(d => d.expiryStatus === "expired").length;
  $("#exp-count").textContent = rows.length === 0 ? "" : plural(rows.length, "document") + (expired ? ", " + expired + " expired" : "");
  if(rows.length === 0){
    $("#exp-table").replaceChildren(emptyBlock("Nothing is expiring", "No document has expired or runs out in the next 30 days. A document needs an expiry date to show up here."));
    return;
  }
  const th = label => h("th", {scope: "col"}, label);
  const body = rows.map(doc => {
    const open = () => openDetail(doc.id);
    return h("tr", {tabindex: "0", onclick: open, onkeydown: e => { if(e.key === "Enter" || e.key === " "){ e.preventDefault(); open(); } }},
      h("td", {class: "mono"}, h("span", {class: "tag"}, doc.code)),
      h("td", {class: "wrap"}, h("div", {class: "c-title"}, doc.title)),
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
