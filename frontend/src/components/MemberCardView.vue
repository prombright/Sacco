<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import UserAvatar from '@/components/UserAvatar.vue'
import SaccoMark from '@/components/SaccoMark.vue'
import { useAuth } from '@/stores/auth'
import { formatDate } from '@/money'

/**
 * The member card on screen, shaped like a bank card: the SACCO, a chip, the member's photo and
 * name, their account number and when they joined, and the same QR code as the printed card.
 */
const props = defineProps<{
  member: { full_name: string; account_no: string | null; employee_no: string | null; joined_on: string | null; photo_url?: string | null }
}>()
const auth = useAuth()
const qr = ref('')

async function makeQr() {
  const QR = (await import('qrcode')).default
  qr.value = await QR.toDataURL(`${auth.sacco?.code ?? 'sacco'}|${props.member.account_no ?? ''}|${props.member.full_name}`, { margin: 0, width: 160, color: { dark: '#151821', light: '#ffffff' } })
}
onMounted(makeQr)
watch(() => props.member.account_no, makeQr)

/** "004" -> "0 0 4"-style spacing for the long number look of a card */
const spaced = (s: string | null) => (s ?? '').split('').join(' ')
</script>

<template>
  <div class="bg-gradient-to-br from-navy-700 via-navy-900 to-navy-950 relative aspect-[1.586] w-full max-w-md overflow-hidden rounded-[1.4rem] p-5 text-white shadow-lift sm:p-6" role="img" :aria-label="`Member card: ${member.full_name}, account ${member.account_no}`">
    <!-- The gold ring and soft light, like the dashboard's money card -->
    <span class="absolute -right-16 -top-20 h-56 w-56 rounded-full border-[3px] border-saffron-400/80" aria-hidden="true" />
    <span class="absolute -bottom-24 -left-10 h-56 w-56 rounded-full bg-white/[0.05]" aria-hidden="true" />

    <div class="relative flex h-full flex-col">
      <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2.5">
          <SaccoMark :sacco="auth.sacco" />
          <div class="leading-tight">
            <p class="text-sm font-bold tracking-wide">{{ auth.sacco?.short_name ?? auth.sacco?.name }}</p>
            <p class="text-[10px] uppercase tracking-[0.18em] text-navy-200">Member card</p>
          </div>
        </div>
        <img v-if="qr" :src="qr" alt="" class="h-14 w-14 rounded-md bg-white p-1 sm:h-16 sm:w-16" />
      </div>

      <!-- The chip -->
      <span class="mt-3 block h-7 w-10 rounded-md bg-gradient-to-br from-saffron-300 to-saffron-500 shadow-inner sm:mt-4" aria-hidden="true">
        <span class="mx-auto mt-[0.55rem] block h-[2px] w-6 bg-saffron-600/60" />
        <span class="mx-auto mt-[0.3rem] block h-[2px] w-6 bg-saffron-600/60" />
      </span>

      <div class="mt-auto flex items-end gap-3">
        <UserAvatar :name="member.full_name" :photo-url="member.photo_url" size="h-14 w-14 text-lg ring-2 ring-white/70 sm:h-16 sm:w-16" />
        <div class="min-w-0 flex-1">
          <p class="font-mono text-base tracking-[0.2em] text-navy-100 sm:text-lg">{{ spaced(member.account_no) }}</p>
          <p class="truncate text-base font-semibold uppercase tracking-wide sm:text-lg">{{ member.full_name }}</p>
          <p class="text-[11px] text-navy-200">
            {{ member.employee_no }}<template v-if="member.joined_on"> · member since {{ formatDate(member.joined_on) }}</template>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
