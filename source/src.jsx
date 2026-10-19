import React,{useEffect,useState} from 'react';
import {createRoot} from 'react-dom/client';
import QRCode from 'qrcode';
import Workspace from './Workspace.jsx';
import './style.css';
const base=import.meta.env.BASE_URL;
const API=base+'api/index.php';
async function req(action,{method='GET',body,form,csrf}={}){
 const opts={method,credentials:'same-origin',headers:{}};
 if(csrf)opts.headers['X-CSRF-Token']=csrf;
 if(body){opts.headers['Content-Type']='application/json';opts.body=JSON.stringify(body)}
 if(form)opts.body=form;
 const response=await fetch(API+'?action='+action,opts);
 const data=await response.json().catch(()=>({error:'Unexpected server response'}));
 if(!response.ok)throw new Error(data.error||'Request failed');
 return data;
}
function App(){
 const [csrf,setCsrf]=useState(''),[authenticated,setAuth]=useState(false),[email,setEmail]=useState(''),[password,setPassword]=useState('');
 const [events,setEvents]=useState([]),[eventName,setEventName]=useState(''),[selected,setSelected]=useState(null),[review,setReview]=useState({media:[],guestbook:[]});
 const [slug,setSlug]=useState(new URLSearchParams(location.search).get('event')||''),[publicData,setPublicData]=useState(null);
 const [guestName,setGuestName]=useState(''),[file,setFile]=useState(null),[message,setMessage]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false),[qr,setQr]=useState('');
 useEffect(()=>{(async()=>{try{const token=await req('csrf');setCsrf(token.csrf);const session=await req('me');setAuth(session.authenticated);if(session.authenticated)await loadEvents()}catch(e){setNotice(e.message)}})()},[]);
 useEffect(()=>{if(slug)loadPublic(slug)},[slug]);
 useEffect(()=>{if(selected)QRCode.toDataURL(location.origin+base+'?event='+encodeURIComponent(selected.slug),{width:320,margin:2}).then(setQr).catch(()=>setQr(''))},[selected]);
 async function loadEvents(){try{setEvents((await req('events')).events)}catch(e){setNotice(e.message)}}
 async function loadPublic(s){try{setPublicData(await req('public_event&slug='+encodeURIComponent(s)))}catch(e){setPublicData(null);setNotice(e.message)}}
 async function login(e){e.preventDefault();setBusy(true);setNotice('');try{const fresh=await req('csrf');setCsrf(fresh.csrf);let x;try{x=await req('login',{method:'POST',body:{email,password},csrf:fresh.csrf})}catch(err){if(!/CSRF validation failed/i.test(err.message))throw err;const retry=await req('csrf');setCsrf(retry.csrf);x=await req('login',{method:'POST',body:{email,password},csrf:retry.csrf})}setCsrf(x.csrf);setAuth(true);setPassword('');await loadEvents()}catch(e){setNotice(e.message)}finally{setBusy(false)}}
 async function createEvent(e){e.preventDefault();setBusy(true);try{await req('create_event',{method:'POST',body:{name:eventName},csrf});setEventName('');await loadEvents();setNotice('Event created')}catch(e){setNotice(e.message)}finally{setBusy(false)}}
 async function setStatus(status){try{await req('event_status',{method:'POST',body:{id:selected.id,status},csrf});setSelected({...selected,status});await loadEvents();setNotice('Event updated')}catch(e){setNotice(e.message)}}
 async function loadReview(ev){setSelected(ev);try{setReview(await req('moderation&event_id='+ev.id))}catch(e){setNotice(e.message)}}
 async function moderate(type,id,status){try{await req('moderate',{method:'POST',body:{type,id,status},csrf});await loadReview(selected)}catch(e){setNotice(e.message)}}
 async function upload(e){e.preventDefault();if(!file)return;const form=new FormData();form.append('slug',slug);form.append('guest_name',guestName);form.append('file',file);setBusy(true);try{await req('guest_upload',{method:'POST',form,csrf});setNotice('Photograph submitted for host approval');setFile(null);e.target.reset()}catch(e){setNotice(e.message)}finally{setBusy(false)}}
 async function leaveNote(e){e.preventDefault();setBusy(true);try{await req('guestbook_submit',{method:'POST',body:{slug,name:guestName,message},csrf});setMessage('');setNotice('Message submitted for approval')}catch(e){setNotice(e.message)}finally{setBusy(false)}}
 return <div className="shell"><header><strong>m<span>·</span> media box</strong><small>THEMEDIAEVENT</small></header><main>
 {slug?<><button className="textbutton" onClick={()=>{history.pushState({},'',base);setSlug('');setPublicData(null);setNotice('')}}>← Home</button>{publicData?<><p className="eyebrow">A SHARED CELEBRATION</p><h1>{publicData.event.name}</h1><p>Every perspective makes the story more complete.</p><div className="gallery">{publicData.media.map(m=><figure key={m.id}><img loading="lazy" src={API+'?action=media_file&id='+m.id} alt={'Memory contributed by '+(m.guest_name||'a guest')}/><figcaption>{m.guest_name||'Guest'}</figcaption></figure>)}</div><div className="twocol"><form className="panel" onSubmit={upload}><h2>Contribute a memory</h2><label>Your name<input maxLength="120" value={guestName} onChange={e=>setGuestName(e.target.value)} placeholder="Optional"/></label><label>Photograph<input type="file" accept="image/jpeg,image/png,image/webp" onChange={e=>setFile(e.target.files?.[0]||null)} required/></label><button disabled={busy||!csrf}>Submit photograph</button></form><form className="panel" onSubmit={leaveNote}><h2>Leave a message</h2><label>Your name<input required maxLength="120" value={guestName} onChange={e=>setGuestName(e.target.value)}/></label><label>Your message<textarea required maxLength="1500" value={message} onChange={e=>setMessage(e.target.value)}/></label><button disabled={busy||!csrf}>Submit message</button></form></div><section className="panel"><h2>Words worth keeping</h2>{publicData.guestbook.map((n,i)=><blockquote key={i}>{n.message}<small> — {n.guest_name}</small></blockquote>)}</section></>:<div className="panel">This event isn't publicly available.</div>}</>:
 <><p className="eyebrow">YOUR PRIVATE MEMORY STUDIO</p><h1>Every beautiful moment,<br/><em>together.</em></h1><p>Collect, curate and preserve the moments worth keeping.</p>
 {!authenticated?<form className="panel" onSubmit={login}><h2>Host sign in</h2><label>Email<input type="email" required value={email} onChange={e=>setEmail(e.target.value)}/></label><label>Password<input type="password" required value={password} onChange={e=>setPassword(e.target.value)}/></label><button disabled={busy||!csrf}>Sign in</button></form>:<Workspace events={events} selected={selected} review={review} qr={qr} csrf={csrf} api={req} loadEvents={loadEvents} loadReview={loadReview} setStatus={setStatus} moderate={moderate} createEvent={createEvent} eventName={eventName} setEventName={setEventName} busy={busy}/></>}
 <p role="status" className="notice">{notice}</p></main><footer>MEDIA BOX · THEMEDIAEVENT · PRIVATE MEMORIES</footer></div>
}
createRoot(document.getElementById('root')).render(<App/>);
