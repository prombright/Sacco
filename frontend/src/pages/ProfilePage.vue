<script setup lang="ts">
import { CameraIcon, ChatBubbleLeftRightIcon, DevicePhoneMobileIcon, DocumentCheckIcon, DocumentTextIcon, IdentificationIcon } from '@heroicons/vue/24/outline'
import UserAvatar from '@/components/UserAvatar.vue'
import MemberCardView from '@/components/MemberCardView.vue'
import { removePhoto, uploadPhoto } from '@/photos'
import { useAuth } from '@/stores/auth'
import { t } from '@/i18n'
import { canInstall, install, installed, isIos } from '@/pwa'
import { downloadMemberCard, downloadMembershipForm, downloadShareCertificate } from '@/documents'
import LanguagePicker from '@/components/LanguagePicker.vue'
import { onMounted, ref } from 'vue'
import { api, ApiError } from '@/api'
import type { NextOfKin, User } from '@/types'
import MemberDetails from '@/components/MemberDetails.vue'
import NextOfKinEditor from '@/components/NextOfKinEditor.vue'
import TwoStepCard from '@/components/TwoStepCard.vue'

/** A member's own details and next of kin */
const user = ref<User | null>(null)
const kin = ref<NextOfKin[]>([])
const error = ref('')

const auth = useAuth()
const making = ref(false)
const who = () => ({ sacco: auth.sacco, user: auth.user?.full_name ?? null })
async function card() {
  making.value = true
  try {
    await downloadMemberCard(user.value!, who())
  } finally {
    making.value = false
  }
}
/** Everything the SACCO holds about me, to keep or correct and hand in */
async function form() {
  making.value = true
  try {
    const s = await api.get<{ balances: { savings: string; shares: string } }>('/me/summary').catch(() => null)
    await downloadMembershipForm(user.value!, kin.value, s?.balances ?? null, who())
  } finally {
    making.value = false
  }
}
async function certificate() {
  making.value = true
  try {
    const s = await api.get<{ balances: { shares: string }; share_price: string }>('/me/summary')
    const capital = Number(s.balances.shares)
    const price = Number(s.share_price)
    await downloadShareCertificate(user.value!, price > 0 ? Math.floor(capital / price) : 0, price, capital, who())
  } finally {
    making.value = false
  }
}

// Messages by email
const msgSettings = ref<{ email: string | null; email_alerts: boolean } | null>(null)
const savingMsg = ref(false)
async function toggleEmail(e: Event) {
  savingMsg.value = true
  try {
    msgSettings.value = await api.patch('/me/message-settings', { email_alerts: (e.target as HTMLInputElement).checked })
  } finally {
    savingMsg.value = false
  }
}
api.get<{ email: string | null; email_alerts: boolean }>('/me/message-settings').then((s) => (msgSettings.value = s)).catch(() => {})

// The profile photo
const photoBusy = ref(false)
const photoError = ref('')
function photoSaved(u: User) {
  if (user.value) user.value.photo_url = u.photo_url
  if (auth.user) auth.user.photo_url = u.photo_url
}
async function pickPhoto(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  photoError.value = ''
  photoBusy.value = true
  try {
    photoSaved(await uploadPhoto(file))
  } catch (err) {
    photoError.value = (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'The photo couldn\'t be saved. Please try again.'
  } finally {
    photoBusy.value = false
  }
}
async function dropPhoto() {
  photoBusy.value = true
  try {
    photoSaved(await removePhoto())
  } finally {
    photoBusy.value = false
  }
}

onMounted(async () => {
  try {
    const res = await api.get<{ user: User; next_of_kin: NextOfKin[] }>('/me/profile')
    user.value = res.user
    kin.value = res.next_of_kin
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load your profile.'
  }
})
</script>

<template>
  <div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <p class="eyebrow">{{ t('My account') }}</p>
        <h1 class="mt-1 text-2xl font-bold">{{ t('My profile') }}</h1>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" :disabled="!user || making" @click="form"><DocumentTextIcon class="h-4 w-4" />My membership form</button>
        <button type="button" class="btn-secondary" :disabled="!user || making" @click="certificate"><DocumentCheckIcon class="h-4 w-4" />{{ t('Share certificate') }}</button>
      </div>
    </div>
    <p class="mt-1 text-sm text-gray-600">Your details as the SACCO has them. If something is wrong, tell the SACCO office.</p>
    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
    <!-- My card, and my photo -->
    <div v-if="user" class="mt-6 grid items-center gap-6 lg:grid-cols-[minmax(0,28rem)_1fr]">
      <MemberCardView :member="user" class="reveal" />
      <section class="card p-5" :class="user.photo_url ? '' : 'border-saffron-300 bg-saffron-50/60'">
        <div class="flex items-center gap-4">
          <UserAvatar :name="user.full_name" :photo-url="user.photo_url" size="h-16 w-16 text-xl" />
          <div class="min-w-0 flex-1">
            <h2 class="font-semibold">{{ user.photo_url ? 'Your photo' : 'Add your photo' }}</h2>
            <p class="text-sm text-gray-600">{{ user.photo_url ? 'It shows on your card, in the app and to the SACCO office.' : 'Put a face to your card: the office and your guarantors will know it is you.' }}</p>
          </div>
        </div>
        <p v-if="photoError" class="mt-2 text-sm text-red-700" role="alert">{{ photoError }}</p>
        <div class="mt-4 flex flex-wrap gap-2">
          <label class="cursor-pointer" :class="[user.photo_url ? 'btn-secondary' : 'btn-primary', { 'pointer-events-none opacity-60': photoBusy }]">
            <CameraIcon class="h-4 w-4" aria-hidden="true" />{{ photoBusy ? 'Saving…' : user.photo_url ? t('Change photo') : t('Add a photo') }}
            <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="photoBusy" @change="pickPhoto" />
          </label>
          <button v-if="user.photo_url" type="button" class="btn-ghost" :disabled="photoBusy" @click="dropPhoto">{{ t('Remove photo') }}</button>
          <button type="button" class="btn-ghost" :disabled="making" @click="card"><IdentificationIcon class="h-4 w-4" />Print my card</button>
        </div>
      </section>
    </div>

    <div v-if="user" class="mt-6 grid gap-6 lg:grid-cols-2">
      <MemberDetails :user="user" />
      <NextOfKinEditor :people="kin" save-url="/me/next-of-kin" @saved="kin = $event" />
    </div>
    <section class="card mt-6 p-5">
      <div class="flex flex-wrap items-start gap-4">
        <ChatBubbleLeftRightIcon class="h-8 w-8 shrink-0 text-brand" aria-hidden="true" />
        <div class="min-w-[14rem] flex-1">
          <h2 class="font-semibold">{{ t('My messages') }}</h2>
          <p class="text-sm text-gray-600">Every change to your account, loan news and payment reminders come to <RouterLink :to="{ name: 'messages' }" class="font-semibold text-brand hover:underline">{{ t('Messages') }}</RouterLink>, like text messages.</p>
          <template v-if="msgSettings">
            <label v-if="msgSettings.email" class="mt-3 flex items-start gap-2 text-sm">
              <input type="checkbox" class="mt-0.5 accent-brand" :checked="msgSettings.email_alerts" :disabled="savingMsg" @change="toggleEmail" />
              <span>Also send each one to <span class="font-medium">{{ msgSettings.email }}</span></span>
            </label>
            <p v-else class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">There's no email on your account. Ask the SACCO office to add one, to get your messages by email and to reset your password yourself.</p>
          </template>
        </div>
      </div>
    </section>
    <TwoStepCard class="mt-6" />
    <section v-if="!installed" class="card mt-6 flex flex-wrap items-center gap-4 p-5">
      <DevicePhoneMobileIcon class="h-8 w-8 text-brand" aria-hidden="true" />
      <div class="min-w-[14rem] flex-1">
        <h2 class="font-semibold">The app on your phone</h2>
        <p class="text-sm text-gray-600">
          <template v-if="canInstall">Add it to your home screen: it opens straight away, like any other app.</template>
          <template v-else-if="isIos">In Safari, tap the Share button, then "Add to Home Screen".</template>
          <template v-else>Open this page in Chrome on your phone and choose "Install app" or "Add to Home screen" from the menu.</template>
        </p>
      </div>
      <button v-if="canInstall" type="button" class="btn-primary" @click="install">{{ t('Install the app') }}</button>
      <LanguagePicker />
    </section>
  </div>
</template>
