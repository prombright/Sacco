<script setup lang="ts">
import PageHeader from '@/components/PageHeader.vue'
import PasswordInput from '@/components/PasswordInput.vue'
import { onMounted, reactive, ref } from 'vue'
import { ArrowDownTrayIcon, CheckCircleIcon, EnvelopeIcon, ExclamationTriangleIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline'
import { api, ApiError } from '@/api'
import { toast } from '@/toast'
import { useAuth } from '@/stores/auth'

/**
 * Copies of the SACCO's data kept off the server: a download now, and a password-locked copy
 * emailed every night to the addresses set here.
 */
const auth = useAuth()
const started = ref(false)
function download() {
  started.value = true
  window.location.href = import.meta.env.BASE_URL + 'api/backup'
}

interface Offsite { emails: string; password_set: boolean; can_lock: boolean; mail_working: boolean; last_sent: string | null; last_error: string | null }
const offsite = ref<Offsite | null>(null)
const form = reactive({ emails: '', password: '' })
const error = ref('')
const saving = ref(false)
const sending = ref(false)
async function load() {
  offsite.value = await api.get<Offsite>('/backup/offsite')
  form.emails = offsite.value.emails
}
onMounted(() => auth.isSuperAdmin && load().catch(() => {}))
async function save() {
  error.value = ''
  saving.value = true
  try {
    offsite.value = await api.patch<Offsite>('/backup/offsite', form)
    form.password = ''
    toast('Saved: a copy goes out every night')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t save.'
  } finally {
    saving.value = false
  }
}
async function sendNow() {
  error.value = ''
  sending.value = true
  try {
    offsite.value = await api.post<Offsite>('/backup/offsite/send')
    toast('A copy has been emailed')
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t send it.'
    await load().catch(() => {})
  } finally {
    sending.value = false
  }
}
const when = (s: string) => {
  const [at, ...rest] = s.split(' · ')
  return { at: new Date(at.replace(' ', 'T')).toLocaleString('en-GB', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }), rest: rest.join(' · ') }
}
</script>

<template>
  <div>
    <PageHeader title="Backups">
      Copies of {{ auth.sacco?.name }}'s data: members, balances, loans, the books and the audit log. If the server is ever lost, the SACCO can be restored from one of them.
    </PageHeader>

    <!-- Every night, by email -->
    <section v-if="auth.isSuperAdmin" class="card mt-5 p-6">
      <div class="flex items-start gap-4">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-navy-50 text-navy-800"><EnvelopeIcon class="h-6 w-6" aria-hidden="true" /></span>
        <div class="min-w-0">
          <h2 class="font-semibold">A copy by email every night</h2>
          <p class="mt-1 text-sm text-gray-600">
            Each night the whole database is put in a zip locked with your backup password (AES-256) and emailed to the addresses below, so a copy is always kept away from the server.
            Without the password the file can't be opened. Open it with 7-Zip or WinRAR.
          </p>
        </div>
      </div>

      <div v-if="offsite" class="mt-4">
        <p v-if="offsite.last_error" class="flex gap-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800"><ExclamationTriangleIcon class="h-5 w-5 shrink-0" />Last attempt failed: {{ offsite.last_error }}</p>
        <p v-else-if="offsite.last_sent" class="flex gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          <CheckCircleIcon class="h-5 w-5 shrink-0" />Last copy sent {{ when(offsite.last_sent).at }} · {{ when(offsite.last_sent).rest }}
        </p>
        <p v-else class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Not set up yet: no copy has been emailed.</p>
        <p v-if="!offsite.can_lock" class="mt-2 text-sm text-amber-800">This server can't make password-locked zips, so no copy can be emailed from here.</p>
        <p v-if="!offsite.mail_working" class="mt-2 text-sm text-amber-800">Email isn't set up on this server, so copies can't be sent.</p>
      </div>

      <form class="mt-5 space-y-4" @submit.prevent="save">
        <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>
        <div>
          <label for="bk-emails" class="label">Email it to</label>
          <input id="bk-emails" v-model="form.emails" class="input" placeholder="e.g. chairperson@example.com, treasurer@example.com" autocomplete="off" />
          <p class="mt-1 text-xs text-gray-500">Up to 5 addresses, separated by commas. Use committee members' own email accounts, not the office's.</p>
        </div>
        <div>
          <label for="bk-pass" class="label">Backup password <span class="font-normal text-gray-400">{{ offsite?.password_set ? '(set; type a new one to change it)' : '' }}</span></label>
          <PasswordInput id="bk-pass" v-model="form.password" autocomplete="new-password" />
          <p class="mt-1 text-xs text-gray-500">At least 12 characters. Write it down and keep it in the safe: without it no copy can be opened, and it is never shown again.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="btn-primary" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
          <button type="button" class="btn-secondary" :disabled="sending || !offsite?.password_set || !offsite?.emails" @click="sendNow">{{ sending ? 'Sending…' : 'Send a copy now' }}</button>
        </div>
      </form>
    </section>

    <!-- Now, as a download -->
    <div class="card mt-5 p-6">
      <div class="flex items-start gap-4">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl icon-gold"><ShieldCheckIcon class="h-6 w-6" aria-hidden="true" /></span>
        <div>
          <p class="font-semibold">Download a copy now</p>
          <p class="mt-1 text-sm text-gray-600">An extra copy you keep yourselves, for example once a month and before the AGM, on a flash disk in the office safe. A copy is also made on the server every time the system is updated. The file holds members' personal details, so treat it like the member register.</p>
        </div>
      </div>
      <button v-if="auth.isSuperAdmin" type="button" class="btn-primary mt-6" @click="download"><ArrowDownTrayIcon class="h-4 w-4" />Download a backup now</button>
      <p v-else class="mt-4 text-sm text-gray-500">Only the super admin can download or set up backups.</p>
      <p v-if="started" class="mt-3 text-sm text-gray-500">Your download is starting…</p>
    </div>
  </div>
</template>
