<script setup lang="ts">
/**
 * The public page's "see it on any screen": the member app on a phone and the office on a laptop,
 * drawn here with made-up names and figures (never real members' money on a public page).
 */
const bars = [38, 52, 47, 61, 58, 72, 69, 84]
</script>

<template>
  <div class="relative mx-auto flex max-w-5xl flex-col items-center gap-10 lg:flex-row lg:items-end lg:justify-center lg:gap-0" aria-hidden="true">
    <!-- Laptop: the office -->
    <div class="w-full max-w-2xl lg:-mr-14">
      <div class="rounded-t-2xl border-[10px] border-b-0 border-[#1c2030] bg-[#1c2030] shadow-lift">
        <div class="flex h-[17rem] overflow-hidden rounded-t-md bg-[#f5f3ee] sm:h-[20rem]">
          <div class="w-28 shrink-0 bg-gradient-to-b from-navy-950 to-[#090c2e] p-3 text-[9px] text-navy-100 sm:w-36">
            <div class="flex items-center gap-1.5">
              <span class="flex h-5 w-5 items-center justify-center rounded bg-brand text-[8px] font-bold text-white">SM</span>
              <span class="font-bold text-white">SACCO office</span>
            </div>
            <ul class="mt-4 space-y-2">
              <li v-for="(m, i) in ['Dashboard', 'Members', 'Savings & shares', 'Loans', 'Cash book', 'Reports']" :key="m" class="rounded px-1.5 py-1" :class="i === 0 ? 'bg-white/10 font-semibold text-white' : ''">{{ m }}</li>
            </ul>
          </div>
          <div class="min-w-0 flex-1 p-3 sm:p-4">
            <div class="rounded-lg bg-gradient-to-br from-navy-800 to-navy-950 p-3 text-white">
              <p class="text-[8px] font-semibold uppercase tracking-wider text-saffron-300">SACCO office</p>
              <p class="text-sm font-bold sm:text-base">Good morning, Peter</p>
              <p class="mt-1 text-[9px] text-navy-100">Member funds <span class="text-xs font-bold text-white">UGX 412,750,000</span></p>
            </div>
            <div class="mt-2 grid grid-cols-3 gap-2">
              <div v-for="k in [['Loans on time', '94%'], ['Outstanding', 'UGX 288M'], ['Members', '186']]" :key="k[0]" class="rounded-lg border border-[#e7e3da] bg-white p-2">
                <p class="text-[7px] font-semibold uppercase tracking-wide text-[#78716c]">{{ k[0] }}</p>
                <p class="text-[11px] font-bold text-[#151821] sm:text-xs">{{ k[1] }}</p>
              </div>
            </div>
            <div class="mt-2 rounded-lg border border-[#e7e3da] bg-white p-2">
              <p class="text-[8px] font-semibold text-[#151821]">Repayments each month</p>
              <div class="mt-2 flex h-16 items-end gap-1.5 sm:h-24">
                <span v-for="(b, i) in bars" :key="i" class="flex-1 rounded-t bg-saffron-500/90" :style="{ height: b + '%' }" />
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="mx-auto h-3 w-[104%] -translate-x-[2%] rounded-b-xl bg-gradient-to-b from-[#c9ccd4] to-[#9aa0ab]" />
    </div>

    <!-- Phone: a member -->
    <div class="relative z-10 w-56 shrink-0 rounded-[2.3rem] border-[9px] border-[#1c2030] bg-[#1c2030] shadow-lift sm:w-60">
      <span class="absolute left-1/2 top-1.5 z-10 h-3.5 w-16 -translate-x-1/2 rounded-full bg-[#1c2030]" />
      <div class="h-[27rem] overflow-hidden rounded-[1.7rem] bg-[#f5f3ee]">
        <div class="px-3 pt-7">
          <p class="text-[8px] font-semibold uppercase tracking-wider text-[#78716c]">My account</p>
          <p class="text-sm font-bold text-[#151821]">Good morning, Jane</p>
          <div class="mt-2 grid grid-cols-4 gap-1">
            <span v-for="a in ['Borrow', 'Withdraw', 'Statement', 'Profile']" :key="a" class="rounded-lg border border-[#e7e3da] bg-white py-1.5 text-center text-[7px] font-medium text-[#151821]">
              <span class="mx-auto mb-1 block h-3.5 w-3.5 rounded bg-navy-50" />{{ a }}
            </span>
          </div>
          <div class="mt-2 rounded-xl bg-gradient-to-br from-navy-800 to-navy-950 p-3 text-white">
            <p class="text-[8px] text-navy-100">My money in the SACCO</p>
            <p class="text-lg font-bold"><span class="mr-0.5 text-[9px] opacity-60">UGX</span>4,250,000</p>
            <div class="mt-2 flex h-1.5 overflow-hidden rounded-full bg-white/10"><span class="w-[86%] bg-navy-200" /><span class="ml-px flex-1 bg-saffron-400" /></div>
            <div class="mt-2 grid grid-cols-2 text-[8px]">
              <p><span class="text-navy-100">Savings</span><br /><span class="font-bold">UGX 3,650,000</span></p>
              <p><span class="text-navy-100">Shares</span><br /><span class="font-bold">UGX 600,000</span></p>
            </div>
          </div>
          <div class="mt-2 flex items-center gap-2 rounded-xl border border-[#e7e3da] bg-white p-2">
            <span class="h-6 w-6 rounded-lg bg-navy-50" />
            <span class="text-[8px] leading-tight text-[#151821]"><span class="text-[#78716c]">Next payment</span><br /><span class="font-bold">UGX 186,400</span> · 30 Nov</span>
          </div>
          <div class="mt-2 rounded-xl border border-[#e7e3da] bg-white p-2">
            <p class="text-[7px] font-semibold uppercase tracking-wide text-[#78716c]">Borrowing power</p>
            <p class="text-sm font-bold text-[#151821]">UGX 7,300,000</p>
            <div class="mt-1 h-1 rounded-full bg-brand" />
          </div>
        </div>
        <div class="absolute inset-x-[9px] bottom-[9px] grid grid-cols-5 rounded-b-[1.7rem] border-t border-[#e7e3da] bg-white py-2 text-center text-[7px] text-[#78716c]">
          <span v-for="(tab, i) in ['Home', 'Loans', 'Statement', 'Messages', 'More']" :key="tab" :class="i === 0 ? 'font-semibold text-brand' : ''"><span class="mx-auto mb-0.5 block h-3 w-3 rounded" :class="i === 0 ? 'bg-navy-100' : 'bg-[#e7e3da]'" />{{ tab }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
