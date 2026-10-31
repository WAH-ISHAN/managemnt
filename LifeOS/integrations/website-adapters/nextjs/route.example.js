// Copy to src/app/api/lifeos-admin/route.js in a Next.js App Router project.
import { createConnector } from '../../../lib/lifeos/connector.mjs';
import { createGitHubStore } from '../../../lib/lifeos/github-store.mjs';
export const runtime='nodejs';
export const dynamic='force-dynamic';
const collections=[{id:'pages',label:'Pages',capabilities:['list','create','update','delete'],fields:[
  {name:'title',label:'Title',type:'text',required:true,max_length:200},
  {name:'body',label:'Body',type:'textarea',required:false,max_length:20000},
  {name:'published',label:'Published',type:'boolean',required:false}
]}];
// Set these server-only variables. The frontend must read this content document.
const store=createGitHubStore({owner:process.env.LIFEOS_CONTENT_OWNER || 'CONFIGURE_OWNER',repo:process.env.LIFEOS_CONTENT_REPO || 'CONFIGURE_REPO',path:'src/content/site-content.json',branch:process.env.LIFEOS_CONTENT_BRANCH || 'main'});
export const POST=createConnector({siteName:'My website',collections,store});
