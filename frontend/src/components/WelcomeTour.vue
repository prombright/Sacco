<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { Component } from 'vue'
import { BanknotesIcon, ChatBubbleLeftRightIcon, UserGroupIcon, WalletIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { formatMoney } from '@/money'
import { useAuth } from '@/stores/auth'

/**
 * A member's first sign-in: four short steps with their own figures. Shown once (the server
 * remembers), skippable, and the last step points to Help.
 */
const props = defineProps<{ savings: string; shares: string; withdrawable: string; loanLimit: string; waitReason?: string | null }>()
const emit = defineEmits<{ done: [] }>()
const auth = useAuth()
const step = ref(0)
const first = computed(() => auth.user?.full_name.split(' ')[0] ?? '')
const sacco = computed(() => auth.sacco?.short_name ?? auth.sacco?.name ?? 'the SACCO')

const steps = computed<{ icon: Component; title: string; lines: string[] }[]>(() => [
  {
    icon: WalletIcon,
    title: `Welcome, ${first.value}`,
    lines: [
      `This is your ${sacco.value} account, on your phone or computer, any time.`,
      `You have ${formatMoney(props.savings)} in savings and ${formatMoney(props.shares)} in shares. You can withdraw up to ${formatMoney(props.withdrawable)}; the rest must stay, or is held for colleagues you guarantee.`,
    ],
  },
  {
    icon: BanknotesIcon,
    title: 'Borrowing',
    lines: props.waitReason
      ? [props.waitReason, 'Keep saving: the more you save, the more you can borrow.']
      : [
          `You can borrow up to ${formatMoney(props.loanLimit)} right now, based on your savings.`,
          'Tap "Apply for a loan": you see the monthly payment and the total before you send it, and you choose colleagues to guarantee it.',
        ],
  },
  {
    icon: UserGroupIcon,
    title: 'Guaranteeing colleagues',
    lines: [
      'When a colleague asks you to guarantee their loan, it shows at the top of your dashboard.',
      'You can agree, agree to less, or decline. What you agree to is held from your savings until their loan is repaid.',
    ],
  },
  {
    icon: ChatBubbleLeftRightIcon,
    title: 'Staying in touch',
    lines: [
      'Every payment, loan decision and reminder arrives under Messages and on the bell at the top.',
      'My statement shows every entry. If anything is unclear, Help has plain answers, and the SACCO office is there too.',
    ],
  },
])
const last = computed(() => step.value === steps.value.length - 1)

async function finish() {
  emit('done')
  if (auth.user) auth.user.welcomed = true
  await api.post('/me/welcomed', {}).catch(() => undefined)
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') finish()
  if (e.key === 'ArrowRight' && !last.value) step.value++
  if (e.key === 'ArrowLeft' && step.value > 0) step.value--
}
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-navy-950/60 backdrop-blur-sm sm:items-center sm:p-4">
      <div class="w-full overflow-hidden rounded-t-3xl bg-surface shadow-lift sm:max-w-md sm:rounded-3xl" role="dialog" aria-modal="true" :aria-label="steps[step].title">
        <div class="bg-gradient-to-br from-navy-800 via-navy-900 to-navy-950 relative px-6 pb-6 pt-7 text-white">
          <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-saffron-400 text-navy-950"><component :is="steps[step].icon" class="h-6 w-6" aria-hidden="true" /></span>
          <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-saffron-300">Step {{ step + 1 }} of {{ steps.length }}</p>
          <h2 class="mt-1 text-2xl font-bold">{{ steps[step].title }}</h2>
          <button type="button" class="absolute right-4 top-4 rounded-full px-3 py-1 text-sm text-navy-100 hover:bg-white/10" @click="finish">Skip</button>
        </div>
        <div class="space-y-3 px-6 py-5 text-gray-700">
          <p v-for="l in steps[step].lines" :key="l" class="leading-relaxed">{{ l }}</p>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-line px-6 py-4">
          <div class="flex gap-1.5" aria-hidden="true">
            <span v-for="i in steps.length" :key="i" class="h-2 rounded-full transition-all" :class="i - 1 === step ? 'w-6 bg-brand' : 'w-2 bg-gray-200'" />
          </div>
          <div class="flex gap-2">
            <button v-if="step > 0" type="button" class="btn-ghost" @click="step--">Back</button>
            <button v-if="!last" type="button" class="btn-primary" @click="step++">Next</button>
            <template v-else>
              <RouterLink :to="{ name: 'help' }" class="btn-secondary" @click="finish">Open Help</RouterLink>
              <button type="button" class="btn-primary" @click="finish">Start</button>
            </template>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
