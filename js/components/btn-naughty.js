// js/components/btn-naughty.js
export default {
  name: 'BtnNaughty',
  props: {
    label: { type: String, default: '按鈕' },
    naughty: { type: Boolean, default: false },
    zIndex: { type: Number, default: 30 },
    disabled: { type: Boolean, default: false },
    variant: { type: String, default: 'info' } // Brite 的藍色
  },
  data(){ return { dx:0, dy:0, styleObj:{} }; },
  methods:{
    bounds(){
      // 以按鈕父層寬度限制移動範圍
      const wrap = this.$el?.parentElement;
      const w = wrap ? wrap.clientWidth : 320;
      const maxX = Math.max(40, Math.min(120, Math.floor(w * 0.25)));
      const maxY = 40;
      return { maxX, maxY };
    },
    flee(){
      if (!this.naughty || this.disabled) { this.reset(); return; }
      const { maxX, maxY } = this.bounds();
      const rx = Math.round((Math.random() * 2 - 1) * maxX);
      const ry = Math.round((Math.random() * 2 - 1) * maxY);
      this.dx = rx; this.dy = ry;
      this.styleObj = {
        transform:`translate(${this.dx}px, ${this.dy}px)`,
        transition:'transform .22s',
        position:'relative',
        zIndex:this.zIndex
      };
    },
    reset(){
      this.dx = this.dy = 0;
      this.styleObj = { transform:'translate(0,0)', transition:'transform .18s', position:'relative', zIndex:this.zIndex };
    },
    onMouseEnter(){ this.flee(); },
    onMouseLeave(){ if (!this.naughty) this.reset(); }
  },
  watch:{ naughty(n){ if(!n) this.reset(); } },
  template: `
    <button
      :class="['btn', 'w-100', 'btn-' + variant]"
      :disabled="disabled"
      :style="styleObj"
      @mouseenter="onMouseEnter"
      @mouseleave="onMouseLeave"
      @click="$emit('click')"
    >{{ label }}</button>
  `
};
