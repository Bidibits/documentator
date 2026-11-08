/* ---------- the page strip under a long list ---------- */
// p is what the server sent about the list: {page, pages, total, per}; goto(n) loads that page.
function renderPager(slot, p, goto){
  if(!p || p.total === 0){ slot.replaceChildren(); return; }
  const from = (p.page - 1) * p.per + 1, to = Math.min(p.total, p.page * p.per);
  const step = (label, disabled, page) => {
    const b = h("button", {class: "btn sm", type: "button", onclick: () => goto(page)}, label);
    b.disabled = disabled;
    return b;
  };
  slot.replaceChildren(h("div", {class: "pager"},
    h("span", {}, from + " to " + to + " of " + p.total),
    p.pages > 1 ? h("div", {class: "pager-btns"},
      step("Previous", p.page <= 1, p.page - 1),
      h("span", {}, "Page " + p.page + " of " + p.pages),
      step("Next", p.page >= p.pages, p.page + 1)) : null));
}
