/* ---------- small helpers ---------- */
const $ = selector => document.querySelector(selector);
const plural = (n, word) => n + " " + word + (n === 1 ? "" : "s");
const SVG_NS = "http://www.w3.org/2000/svg";

// Builds an element. Text only ever goes in as text, never as markup, so nothing a person typed can run.
//   h("td", {class:"mono", onclick: fn}, "text", otherElement)
function h(tag, attrs, ...kids){
  const el = document.createElement(tag);
  for(const [k, v] of Object.entries(attrs || {})){
    if(v == null || v === false) continue;
    if(k === "class") el.className = v;
    else if(k.startsWith("on")) el.addEventListener(k.slice(2), v);
    else el.setAttribute(k, v === true ? "" : v);
  }
  for(const kid of kids.flat()){
    if(kid == null || kid === false) continue;
    el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
  }
  return el;
}
function icon(name){
  const svg = document.createElementNS(SVG_NS, "svg"), use = document.createElementNS(SVG_NS, "use");
  svg.setAttribute("class", "ic");
  use.setAttribute("href", "#i-" + name);
  svg.append(use);
  return svg;
}

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
// "2026-11-08" -> "8 Nov 2026"
function fmtDate(day){
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day || "");
  return m ? Number(m[3]) + " " + MONTHS[Number(m[2]) - 1] + " " + m[1] : "";
}
// "2026-11-08T05:34:00.962Z" -> "8 Nov 2026, 13:34" in the viewer's own time zone
function fmtStamp(iso){
  const d = new Date(iso || "");
  if(isNaN(d)) return "";
  const pad = n => String(n).padStart(2, "0");
  return d.getDate() + " " + MONTHS[d.getMonth()] + " " + d.getFullYear() + ", " + pad(d.getHours()) + ":" + pad(d.getMinutes());
}
function fmtSize(bytes){
  if(bytes < 1024) return bytes + " B";
  if(bytes < 1024 * 1024) return (bytes / 1024).toFixed(bytes < 10240 ? 1 : 0) + " KB";
  return (bytes / 1024 / 1024).toFixed(1) + " MB";
}
// whole days from today (viewer's calendar) to a "YYYY-MM-DD" day: negative once it has passed
function daysUntil(day){
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day || "");
  if(!m) return null;
  const now = new Date();
  return Math.round((Date.UTC(+m[1], +m[2] - 1, +m[3]) - Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())) / 864e5);
}

let toastTimer = 0;
function toast(text, tone){
  const box = $("#toast");
  box.replaceChildren(icon(tone === "warn" ? "alert-circle" : "check-circle"), h("span", {}, text));
  box.className = "toast " + (tone || "ok");
  box.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { box.hidden = true; }, 4500);
}

// the server's error codes, in words
function errorText(e){
  switch(e.code){
    case "locked": return "Too many tries. Wait " + plural(Number(e.data && e.data.minutes) || 15, "minute") + " and try again.";
    case "inactive": return "This account is switched off. Ask an administrator.";
    case "bad_login": return "That username and password do not match.";
    case "offline": return "The server did not answer. Check the network and try again.";
    default: return "Could not sign in. Try again.";
  }
}
