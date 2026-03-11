<script setup>
import { onMounted, onBeforeUnmount, ref, watch, defineExpose } from 'vue'

const props = defineProps({
  mode: { type: String, default: 'login' },     // 'login' | 'app'
  interactive: { type: Boolean, default: true },
  speed: { type: Number, default: 1.0 },        // 0.5~2
  density: { type: Number, default: 1.0 },      // 0.5~2
  lineWidth: { type: Number, default: 2 },
})

const host = ref(null)
const canvas = ref(null)
let ctx, raf = 0, cleanup = () => {}
let W=0, H=0, paths=[], pulses=[], mouse={x:-1,y:-1,active:false}, t=0
let paused = false

function cssVars() {
  const root = getComputedStyle(document.documentElement)
  return {
    bg : root.getPropertyValue('--bs-body-bg').trim() || '#0b1220',
    pri: root.getPropertyValue('--bs-primary').trim() || '#6cf',
    acc: root.getPropertyValue('--bs-info').trim() || '#0df',
  }
}

function initCanvas() {
  const el = host.value
  const c  = canvas.value
  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  W = el.clientWidth
  H = el.clientHeight
  c.width  = W * dpr
  c.height = H * dpr
  c.style.width = W+'px'
  c.style.height = H+'px'
  ctx = c.getContext('2d')
  ctx.setTransform(dpr,0,0,dpr,0,0)
}

function makePaths() {
  // 產生「正交折線」的電路路徑
  const count = Math.floor(14 * props.density)
  const stepX = 24, stepY = 16
  paths = []
  for (let i=0;i<count;i++){
    let x = Math.floor(Math.random()*W*0.5)
    let y = Math.floor(Math.random()*H)
    const segs = 8 + Math.floor(Math.random()*6)
    const pts = [[x,y]]
    for (let s=0;s<segs;s++){
      x += (Math.random()<0.5 ? stepX : -stepX)
      y += ([ -stepY,0, stepY ][Math.floor(Math.random()*3)])
      x = Math.max(0, Math.min(W, x))
      y = Math.max(0, Math.min(H, y))
      pts.push([x,y])
    }
    // 預算長度
    let len = 0, seglen=[]
    for(let k=0;k<pts.length-1;k++){
      const dx=pts[k+1][0]-pts[k][0], dy=pts[k+1][1]-pts[k][1]
      const ll = Math.hypot(dx,dy); seglen.push(ll); len+=ll
    }
    paths.push({ pts, len, seglen, phase: Math.random()*Math.PI*2 })
  }
}

function drawBackground() {
  const { bg } = cssVars()
  ctx.fillStyle = props.mode==='login' ? 'rgba(8,12,22,1)' : bg || '#060a14'
  ctx.fillRect(0,0,W,H)
}

function drawPaths() {
  const { pri } = cssVars()
  const lw = props.lineWidth
  for (let i=0;i<paths.length;i++){
    const p = paths[i]
    // 微微發光的基礎線條
    const glow = 180 + Math.floor(60*Math.sin((t*0.04 + i*0.6) ))
    ctx.strokeStyle = `rgba(${glow},${glow},255,${props.mode==='login'?0.55:0.75})`
    ctx.lineWidth = lw
    ctx.beginPath()
    ctx.moveTo(p.pts[0][0], p.pts[0][1])
    for (let k=1;k<p.pts.length;k++) ctx.lineTo(p.pts[k][0], p.pts[k][1])
    ctx.stroke()

    // 滑鼠接近時加亮
    if (props.interactive && mouse.active){
      const near = nearestSegmentDist(p, mouse.x, mouse.y)
      const a = Math.max(0, 1 - near/60)
      if (a>0){
        ctx.strokeStyle = `rgba(140,220,255,${0.35*a})`
        ctx.lineWidth = lw+1
        ctx.beginPath()
        ctx.moveTo(p.pts[0][0], p.pts[0][1])
        for (let k=1;k<p.pts.length;k++) ctx.lineTo(p.pts[k][0], p.pts[k][1])
        ctx.stroke()
      }
    }
  }
}

function nearestSegmentDist(p, x, y){
  // 計算滑鼠到某條 polyline 的最近距離（粗略夠用）
  let best=1e9
  for (let i=0;i<p.pts.length-1;i++){
    const [x1,y1]=p.pts[i], [x2,y2]=p.pts[i+1]
    const vx=x2-x1, vy=y2-y1
    const wx=x-x1, wy=y-y1
    const c1 = vx*wx + vy*wy
    const c2 = vx*vx + vy*vy
    let b = c2 ? Math.max(0, Math.min(1, c1/c2)) : 0
    const px = x1 + b*vx, py = y1 + b*vy
    best = Math.min(best, Math.hypot(px-x, py-y))
  }
  return best
}

function stepPulses(dt) {
  const sp = (props.speed || 1) * (props.mode==='login'? 0.8:1.2)
  for (let i=pulses.length-1;i>=0;i--){
    const pu = pulses[i]
    pu.s += pu.v * sp * dt
    if (pu.s > pu.path.len) pulses.splice(i,1)
  }
}

function drawPulses() {
  const { acc } = cssVars()
  for (const pu of pulses){
    // s -> 座標
    let s = pu.s, x=pu.path.pts[0][0], y=pu.path.pts[0][1]
    for (let k=0;k<pu.path.seglen.length;k++){
      const seg = pu.path.seglen[k]
      const [x1,y1] = pu.path.pts[k]
      const [x2,y2] = pu.path.pts[k+1]
      if (s<=seg){
        const r = s/seg
        x = x1 + (x2-x1)*r
        y = y1 + (y2-y1)*r
        break
      } else s -= seg
    }
    ctx.fillStyle = 'rgba(255,255,255,0.96)'
    ctx.beginPath(); ctx.arc(x,y,3,0,Math.PI*2); ctx.fill()
    ctx.strokeStyle = acc || '#0df'
    ctx.lineWidth = 2
    ctx.beginPath(); ctx.arc(x,y,5,0,Math.PI*2); ctx.stroke()
  }
}

function loop(ts) {
  if (paused){ raf = requestAnimationFrame(loop); return }
  t++
  drawBackground()
  drawPaths()
  stepPulses(1)     // 固定步長，視覺更穩
  drawPulses()
  raf = requestAnimationFrame(loop)
}

function pulseAt(x,y, v=3) {
  // 找最近的路徑，從最近點開始注入一顆脈衝
  let best={path:null, s:0, d:1e9}
  for (const p of paths){
    let acc=0
    for(let i=0;i<p.pts.length-1;i++){
      const [x1,y1]=p.pts[i], [x2,y2]=p.pts[i+1]
      const vx=x2-x1, vy=y2-y1
      const wx=x-x1, wy=y-y1
      const c1 = vx*wx + vy*wy
      const c2 = vx*vx + vy*vy
      const b = c2 ? Math.max(0, Math.min(1, c1/c2)) : 0
      const px = x1 + b*vx, py = y1 + b*vy
      const d = Math.hypot(px-x, py-y)
      if (d<best.d){ best={path:p, s: acc + b*Math.hypot(vx,vy), d} }
      acc += Math.hypot(vx,vy)
    }
  }
  if (best.path){
    pulses.push({ path: best.path, s: best.s, v: 2.2 + Math.random()*1.2 })
  }
}

function pulseRandom(n=1){
  for(let i=0;i<n;i++){
    const p = paths[Math.floor(Math.random()*paths.length)]
    const s = Math.random()*p.len*0.8
    pulses.push({ path:p, s, v: 2.0 + Math.random()*1.5 })
  }
}

function setSpeed(v){ /* 暴露 API，直接改 props 會警告，這裡示意 */ }
function setDensity(v){
  // 重建
  makePaths()
}

defineExpose({ pulse: pulseAt, pulseRandom, setSpeed, setDensity })

// 事件與生命週期
function onMouseMove(e){
  mouse.x = e.clientX
  mouse.y = e.clientY
  mouse.active = true
}
function onClick(e){
  if (!props.interactive) return
  pulseAt(e.clientX, e.clientY)
}
function onVisibility(){
  paused = document.hidden
}

function mountAll(){
  initCanvas()
  makePaths()
  t = 0; paused=false
  loop()
  window.addEventListener('mousemove', onMouseMove, {passive:true})
  window.addEventListener('click', onClick, {passive:true})
  document.addEventListener('visibilitychange', onVisibility)
  // 外部事件總線
  window.addEventListener('circuit-bg:pulse', ev=>{
    const d = ev.detail || {}
    if (typeof d.x==='number' && typeof d.y==='number') pulseAt(d.x, d.y)
    else pulseRandom(1)
  })
  return ()=>{
    cancelAnimationFrame(raf)
    window.removeEventListener('mousemove', onMouseMove)
    window.removeEventListener('click', onClick)
    document.removeEventListener('visibilitychange', onVisibility)
  }
}

onMounted(()=> cleanup = mountAll())
onBeforeUnmount(()=> cleanup && cleanup())

watch(()=>[props.mode, props.density], ()=>{
  cancelAnimationFrame(raf)
  cleanup && cleanup()
  cleanup = mountAll()
})
</script>

<template>
  <div ref="host" class="circuit-bg" :data-mode="mode" aria-hidden="true">
    <canvas ref="canvas"></canvas>
  </div>
</template>

<style scoped>
.circuit-bg{ position:absolute; inset:0; z-index:0; overflow:hidden; }
.circuit-bg[data-mode="login"]{ filter:saturate(.92) brightness(.95); opacity:.9; }
.circuit-bg[data-mode="app"]  { filter:saturate(1.05); opacity:.98; }
canvas{ width:100%; height:100%; display:block; }
:global(.dbg-stage){ position:relative; z-index:1; } /* 你的內容層 */
</style>
