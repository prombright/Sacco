<script setup lang="ts">
import { ref } from 'vue'
import { ArrowDownTrayIcon, ShieldCheckIcon } from '@heroicons/vue/24/outline'
import { useAuth } from '@/stores/auth'

/** A copy of the whole database to keep somewhere safe, off the server */
const auth = useAuth()
const started = ref(false)
function download() {
  started.value = true
  window.location.href = import.meta.env.BASE_URL + 'api/backup'
}
</script>

<template>
  <div class="max-w-3xl">
    <p class="eyebrow">Administration</p>
    <h1 class="mt-1 text-2xl font-bold">Download backup</h1>
    <p class="mt-1 text-gray-600">A complete copy of {{ auth.sacco?.name }}'s database: members, balances, loans, the books and the audit log. Keep it somewhere safe off the server, such as a flash disk in the office safe or a cloud drive only the committee can open.</p>

    <div class="card mt-5 p-6">
      <div class="flex items-start gap-4">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-navy-50 text-navy-800"><ShieldCheckIcon class="h-6 w-6" aria-hidden="true" /></span>
        <div>
          <p class="font-semibold">The server also makes a backup every night</p>
          <p class="mt-1 text-sm text-gray-600">This download is for an extra copy you keep yourselves, for example once a month and before the AGM. The file holds members' personal details, so treat it like the member register.</p>
        </div>
      </div>
      <button v-if="auth.isSuperAdmin" type="button" class="btn-primary mt-6" @click="download"><ArrowDownTrayIcon class="h-4 w-4" />Download a backup now</button>
      <p v-else class="mt-6 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600">Only a super admin can download a backup.</p>
      <p v-if="started" class="mt-3 text-sm text-gray-600">The download starts in a moment. The file ends in .sql.gz; your IT person can restore it with MySQL if ever needed.</p>
    </div>
  </div>
</template>
