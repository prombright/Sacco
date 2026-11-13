<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { Component } from 'vue'
import { BanknotesIcon, ChevronDownIcon, ClipboardDocumentCheckIcon, KeyIcon, MagnifyingGlassIcon, ReceiptPercentIcon, UserGroupIcon, WalletIcon } from '@heroicons/vue/24/outline'
import { api } from '@/api'
import { formatMoney } from '@/money'
import { useAuth } from '@/stores/auth'
import EmptyState from '@/components/EmptyState.vue'

/**
 * Plain answers to what members ask most. The figures come from the SACCO's own rules, so the
 * page stays right when the committee changes them.
 */

interface Product { name: string; interest_rate_monthly: string; max_months: number; savings_multiplier: string; processing_fee_type: string; processing_fee: string; processing_fee_percent: string; min_guarantors: number }
interface Terms {
  sacco: { name: string }
  loan_products: Product[]
  min_member_months: number
  dividend_rate_percent: number
  profit_basis: string
  share_price: number
  min_shares: number
  savings_min_balance: number
  withdrawal_fee_percent: number
  membership_fee_once: number
  subscription_fee_annual: number
}

const auth = useAuth()
const terms = ref<Terms | null>(null)
const q = ref('')
const open = ref<string | null>(null)
onMounted(async () => {
  terms.value = await api.get<Terms>('/public/terms').catch(() => null)
})

const money = (n: number | string) => formatMoney(String(n))
const topics = computed<{ key: string; title: string; icon: Component; items: { q: string; a: string }[] }[]>(() => {
  const t = terms.value
  const main = t?.loan_products[0]
  const fee = (p: Product) => (p.processing_fee_type === 'percent' ? `${p.processing_fee_percent}% of the loan` : money(p.processing_fee))
  const sacco = t?.sacco.name ?? 'the SACCO'
  return [
    {
      key: 'start', title: 'Getting started', icon: KeyIcon,
      items: [
        { q: 'How do I sign in?', a: `Use your employee number from your sign-in slip (for example SM004) and the temporary password on it. The first time, you choose your own password. After that, sign in with your employee number or your email, if the office has added one.` },
        { q: 'I forgot my password', a: `On the sign-in page, tap "Forgot password?". If your email is on your account you get a link to choose a new password. If not, the SACCO office can give you a new temporary password.` },
        { q: 'Can I use it on my phone like an app?', a: `Yes. Open the site on your phone and tap "Install the app" (or "Add to home screen" in your browser's menu). It then opens like any other app. Only tick "Keep me signed in" on your own phone.` },
        { q: 'Where do I see messages from the SACCO?', a: `Under Messages, and on the bell at the top. Money in and out of your account, loan news, reminders and notices from the office all arrive there. If your email is on your account, you get a copy there too.` },
      ],
    },
    {
      key: 'money', title: 'My savings and shares', icon: WalletIcon,
      items: [
        { q: 'What is the difference between savings and shares?', a: `Savings are your money that you can take out, apart from what must stay. Shares make you an owner of ${sacco}: each share costs ${t ? money(t.share_price) : 'a fixed amount'}, every member holds at least ${t?.min_shares ?? 'a few'}, and shares earn the yearly dividend. Shares are only paid back when you leave the SACCO, or moved to another member.` },
        { q: 'Why can\'t I withdraw all my savings?', a: `${t ? money(t.savings_min_balance) : 'A small amount'} must always stay in your account. Savings you have pledged as a guarantor for a colleague are also held until their loan is repaid. "You can withdraw" on your dashboard already takes both into account.` },
        { q: 'How do I take out savings, and what does it cost?', a: `Go to Withdraw savings, enter the amount and send the request. The office approves it and prepares your cheque; you follow it on the same page. The fee is ${t?.withdrawal_fee_percent ?? 'a small'}% of the amount withdrawn.` },
        { q: 'My balance looks wrong', a: `Open My statement: every entry is there with its date and reference, and entries from before the switch to this system are under "From the old system". If something is still missing, the office can check it against your payslip.` },
      ],
    },
    {
      key: 'loans', title: 'Borrowing', icon: BanknotesIcon,
      items: [
        { q: 'How much can I borrow?', a: main ? `Up to ${main.savings_multiplier} times your savings for a ${main.name.toLowerCase()}, less what you still owe on other loans. Your dashboard shows the exact amount under "Borrowing power".` : 'Your dashboard shows the exact amount under "Borrowing power".' },
        { q: 'What does a loan cost?', a: main ? `Interest is ${main.interest_rate_monthly}% a month on what you still owe, so it gets smaller as you repay. There is a processing fee of ${fee(main)}. Before you apply, the application shows your monthly payment and the total you will repay.` : 'The application shows your monthly payment and total cost before you send it.' },
        { q: 'Why do I need guarantors?', a: main ? `Every loan needs ${main.min_guarantors} colleague${main.min_guarantors === 1 ? '' : 's'} who agree to stand behind it with part of their savings, together covering the whole loan. You choose them when you apply; each is asked on their own dashboard.` : 'Colleagues who agree to stand behind the loan with part of their savings.' },
        { q: 'How do I repay?', a: `Repayments come off your pay each month through the payroll. You can also pay at the office, or ask for savings to be moved to your loan. Each payment covers the month's interest first, then the loan itself.` },
        { q: 'What happens if a repayment is late?', a: `You get a reminder before each instalment falls due. Paying late lowers your credit standing, and a poor standing lowers how much you can borrow next time. Paying on time brings it back up.` },
        { q: 'Can I borrow again before my loan is finished?', a: `Yes, with a top-up: the new loan first clears what is left on the old one and you receive the rest. Choose it when you apply.` },
      ],
    },
    {
      key: 'guarantee', title: 'Guaranteeing a colleague', icon: UserGroupIcon,
      items: [
        { q: 'What does it mean to guarantee a loan?', a: `You promise part of your savings in case the borrower doesn't repay. That amount is held: you can't withdraw it until the loan is repaid, and your dashboard shows it as "held for guarantees".` },
        { q: 'Do I have to agree?', a: `No. You can agree to the amount asked, agree to a smaller amount, or decline. The borrower is told and can ask someone else.` },
        { q: 'When do I get my savings back?', a: `As the borrower repays, and in full when the loan is repaid. You get a message when you are released.` },
      ],
    },
    {
      key: 'fees', title: 'Fees and earnings', icon: ReceiptPercentIcon,
      items: [
        { q: 'Which fees do members pay?', a: t ? `A membership fee of ${money(t.membership_fee_once)}, paid once, and a subscription of ${money(t.subscription_fee_annual)} every year. The office can take them from your savings, or you can pay at the office.` : 'A membership fee once, and a subscription every year.' },
        { q: 'What do I earn?', a: t ? `A dividend of ${t.dividend_rate_percent}% of your share capital each year, and a share of what the SACCO's investments earn, by your ${t.profit_basis === 'average_savings' ? 'average savings over the year' : 'savings at the end of the year'}. Your dashboard shows an estimate for this year.` : 'A yearly dividend on your shares, and a share of investment profit.' },
      ],
    },
    {
      key: 'office', title: 'Getting help', icon: ClipboardDocumentCheckIcon,
      items: [
        { q: 'Who do I talk to?', a: `The SACCO office. Bring your employee number, and the reference on any entry you have a question about (it is on your statement and on each receipt).` },
        { q: 'Who can see my account?', a: `Only you and the SACCO office. Guarantors see the loan they are guaranteeing, never your savings. Every change the office makes is recorded with who made it.` },
      ],
    },
  ]
})

const words = computed(() => q.value.toLowerCase().split(/\s+/).filter((w) => w.length > 1))
const shown = computed(() =>
  topics.value
    .map((t) => ({ ...t, items: words.value.length ? t.items.filter((i) => words.value.every((w) => (i.q + ' ' + i.a).toLowerCase().includes(w))) : t.items }))
    .filter((t) => t.items.length),
)
</script>

<template>
  <div class="max-w-3xl">
    <p class="eyebrow">{{ auth.isAdmin ? 'For members' : 'My account' }}</p>
    <h1 class="mt-1 text-2xl font-bold">Help</h1>
    <p class="mt-1 text-gray-600">Plain answers to what members ask most.</p>

    <div class="relative mt-5">
      <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" aria-hidden="true" />
      <input v-model="q" type="search" class="input pl-10" placeholder="Search, e.g. withdraw, guarantor, interest" aria-label="Search the help" />
    </div>

    <section v-for="t in shown" :key="t.key" class="mt-6" :aria-labelledby="`help-${t.key}`">
      <h2 :id="`help-${t.key}`" class="flex items-center gap-2 font-semibold">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-navy-50 text-navy-800"><component :is="t.icon" class="h-4 w-4" aria-hidden="true" /></span>
        {{ t.title }}
      </h2>
      <ul class="card mt-3 divide-y divide-line">
        <li v-for="i in t.items" :key="i.q">
          <button
            type="button"
            class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left font-medium"
            :aria-expanded="open === i.q || words.length > 0"
            @click="open = open === i.q ? null : i.q"
          >
            {{ i.q }}
            <ChevronDownIcon class="h-5 w-5 shrink-0 text-gray-400 transition" :class="open === i.q || words.length ? 'rotate-180' : ''" aria-hidden="true" />
          </button>
          <p v-if="open === i.q || words.length" class="px-5 pb-4 text-sm leading-relaxed text-gray-700">{{ i.a }}</p>
        </li>
      </ul>
    </section>

    <div v-if="!shown.length" class="card mt-6">
      <EmptyState title="Nothing matches" :text="`No answer mentions “${q}”. Try another word, or ask the SACCO office.`" :icon="MagnifyingGlassIcon" />
    </div>
  </div>
</template>
