/* ---------- the server ---------- */
// The one place the page talks to the API. The X-Documentator header is what the server checks in place of a CSRF
// token. A 401 from anywhere but the sign-in itself means the server no longer knows this browser.
let onSessionEnded = null;

async function api(method, path, body){
  const options = {method: method, headers: {"X-Documentator": "1"}, credentials: "same-origin", cache: "no-store"};
  if(body !== undefined){
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }
  let response;
  try{ response = await fetch(path, options); }
  catch(e){ const err = new Error("offline"); err.code = "offline"; err.status = 0; err.data = {}; throw err; }

  let data = null;
  try{ data = await response.json(); }catch(e){ data = null; }
  if(!response.ok){
    const err = new Error((data && data.error) || ("http_" + response.status));
    err.code = (data && data.error) || ("http_" + response.status);
    err.status = response.status;
    err.data = data || {};
    if(response.status === 401 && path !== "/api/login" && onSessionEnded) onSessionEnded();
    throw err;
  }
  return data || {};
}
