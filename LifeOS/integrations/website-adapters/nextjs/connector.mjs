import { timingSafeEqual, randomUUID } from 'node:crypto';

export class ConnectorError extends Error {
  constructor(status,message) { super(message); this.status=status; }
}
const protocol='lifeos-admin-v1';
const maxBody=262144;
const actions={manifest:['protocol','action'],list:['protocol','action','collection','offset','limit'],create:['protocol','action','collection','data'],update:['protocol','action','collection','id','version','data'],delete:['protocol','action','collection','id','version']};
const object=value=>value!==null && typeof value==='object' && !Array.isArray(value);
const integer=(value,min,max)=>Number.isInteger(value) && value>=min && value<=max;
function dataFor(collection,input) {
  if (!object(input) || Object.keys(input).some(key=>!collection.fields.some(field=>field.name===key))) throw new ConnectorError(422,'Unknown or invalid record fields.');
  const result={};
  for (const field of collection.fields) {
    let value=input[field.name] ?? (field.type==='boolean' ? false : '');
    if (field.type==='boolean') { if (typeof value!=='boolean') throw new ConnectorError(422,'Invalid boolean field.'); }
    else if (field.type==='number') { if (value==='' && !field.required) value=null; else if (typeof value!=='number' || !Number.isFinite(value) || Math.abs(value)>1e12) throw new ConnectorError(422,'Invalid number.'); }
    else {
      if (typeof value!=='string' || value.includes('\0') || [...value].length>(field.max_length ?? 2000)) throw new ConnectorError(422,'Invalid text field.');
      value=value.trim();
      if (field.required && !value) throw new ConnectorError(422,'Complete all required fields.');
      if (field.type==='date' && value && (!/^\d{4}-\d{2}-\d{2}$/.test(value) || !Number.isFinite(Date.parse(value+'T00:00:00Z')) || new Date(value+'T00:00:00Z').toISOString().slice(0,10)!==value)) throw new ConnectorError(422,'Invalid date.');
    }
    result[field.name]=value;
  }
  return result;
}
async function bodyFor(request) {
  if (!request.headers.get('content-type')?.toLowerCase().startsWith('application/json')) throw new ConnectorError(415,'Use application/json.');
  if (Number(request.headers.get('content-length'))>maxBody) throw new ConnectorError(413,'Request too large.');
  const reader=request.body?.getReader();
  if (!reader) throw new ConnectorError(400,'A JSON request is required.');
  const chunks=[]; let size=0;
  while (true) {
    const {done,value}=await reader.read(); if (done) break;
    size+=value.byteLength;
    if (size>maxBody) { await reader.cancel(); throw new ConnectorError(413,'Request too large.'); }
    chunks.push(Buffer.from(value));
  }
  try { return JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new ConnectorError(400,'Invalid JSON.'); }
}

/** Node/Next.js Web Request adapter. Storage must implement atomic compare-and-swap. */
export function createConnector({siteName,collections,store,token=()=>process.env.LIFEOS_CONNECTOR_TOKEN,allowLocalHttp=false}) {
  return async function POST(request) {
    const reply=(data,status=200)=>new Response(JSON.stringify({protocol,...data}),{status,headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});
    try {
      const url=new URL(request.url);
      if (url.protocol!=='https:' && !(allowLocalHttp && ['127.0.0.1','localhost','[::1]'].includes(url.hostname))) throw new ConnectorError(403,'HTTPS is required.');
      if (request.method!=='POST') throw new ConnectorError(405,'Use POST.');
      const secret=token();
      if (typeof secret!=='string' || !/^[\x21-\x7e]{32,4096}$/.test(secret)) throw new ConnectorError(503,'Configure the server-side connector secret.');
      const supplied=request.headers.get('authorization') ?? '';
      const expected='Bearer '+secret;
      if (Buffer.byteLength(supplied)!==Buffer.byteLength(expected) || !timingSafeEqual(Buffer.from(supplied),Buffer.from(expected))) throw new ConnectorError(401,'Authentication failed.');
      const input=await bodyFor(request);
      if (!object(input) || input.protocol!==protocol || !Object.hasOwn(actions,input.action) || Object.keys(input).some(key=>!actions[input.action].includes(key))) throw new ConnectorError(422,'Unsupported request.');
      if (input.action==='manifest') {
        const snapshot=await store.read();
        if (!collections.every(collection=>Array.isArray(snapshot.document?.collections?.[collection.id]))) throw new ConnectorError(503,'Website content is not configured.');
        return reply({site:{name:siteName},collections});
      }
      const collection=collections.find(item=>item.id===input.collection);
      if (!collection) throw new ConnectorError(422,'Unknown collection.');
      if (!collection.capabilities.includes(input.action)) throw new ConnectorError(403,'This action is not allowed.');
      // Load the real website store on every request; never keep content in serverless memory.
      const snapshot=await store.read();
      const document=structuredClone(snapshot.document);
      const records=document.collections?.[collection.id];
      if (!Array.isArray(records)) throw new ConnectorError(503,'Website content is not configured.');
      if (input.action==='list') {
        const offset=input.offset ?? 0,limit=input.limit ?? 50;
        if (!integer(offset,0,1000000) || !integer(limit,1,50)) throw new ConnectorError(422,'Invalid record page.');
        return reply({records:records.slice(offset,offset+limit),total:records.length,offset,limit});
      }
      let record,index=-1;
      if (input.action!=='create') {
        if (typeof input.id!=='string' || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/.test(input.id) || !integer(input.version,1,2147483646)) throw new ConnectorError(422,'Invalid record or version.');
        index=records.findIndex(item=>item.id===input.id); record=records[index];
        if (!record) throw new ConnectorError(404,'Record not found.');
        if (record.version!==input.version) throw new ConnectorError(409,'This record changed. Refresh before saving.');
      }
      if (input.action==='delete') records.splice(index,1);
      else {
        record={id:record?.id ?? randomUUID(),version:record ? record.version+1 : 1,data:dataFor(collection,input.data)};
        if (index===-1) records.push(record); else records[index]=record;
      }
      if (collection.validate) collection.validate(records);
      if (Buffer.byteLength(JSON.stringify(document))>524288) throw new ConnectorError(413,'Website content is too large for this adapter.');
      await store.write(document,snapshot.revision,`LifeOS: ${input.action} ${collection.id}`);
      return reply(input.action==='delete' ? {deleted:true} : {record});
    } catch (error) {
      return reply({error:error instanceof ConnectorError ? error.message : 'Website connector is unavailable.'},error instanceof ConnectorError ? error.status : 503);
    }
  };
}
