<script setup lang="ts">
import { ask } from '@/confirm'
import { onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { User } from '@/types'
import { formatMoney } from '@/money'
import UserForm from './UserForm.vue'
import TemporaryPassword from './TemporaryPassword.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import { KeyIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'

const auth = useAuth()

const users = ref<User[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const q = ref('')
const role = ref('')
const status = ref('active')
const loading = ref(true)
const error = ref('')

const editing = ref<User | null | undefined>(undefined) // undefined = closed, null = adding
const issued = ref<{ user: User; password: string } | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<{ users: User[]; total: number; per_page: number }>('/users', {
      q: q.value || undefined,
      role: role.value || undefined,
      status: status.value || undefined,
      page: page.value,
    })
    users.value = res.users
    total.value = res.total
    perPage.value = res.per_page
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : 'Couldn\'t load users.'
  } finally {
    loading.value = false
  }
}

let typing: ReturnType<typeof setTimeout> | undefined
watch(q, () => {
  clearTimeout(typing)
  typing = setTimeout(() => { page.value = 1; load() }, 300)
})
watch([role, status], () => { page.value = 1; load() })
onMounted(load)

function saved(user: User, temporaryPassword: string | null) {
  editing.value = undefined
  if (temporaryPassword) {
    issued.value = { user, password: temporaryPassword }
  }
  load()
}

function canManage(user: User) {
  return auth.isSuperAdmin || user.role === 'member'
}

async function resetPassword(user: User) {
  if (!await ask(`Give ${user.full_name} a new temporary password? Their current password will stop working.`)) return
  try {
    const res = await api.post<{ user: User; temporary_password: string }>(`/users/${user.id}/reset-password`)
    issued.value = { user: res.user, password: res.temporary_password }
    load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Couldn\'t reset the password.')
  }
}

const statusStyle: Record<string, string> = {
  active: 'bg-green-50 text-green-700',
  suspended: 'bg-amber-50 text-amber-700',
  left: 'bg-gray-100 text-gray-600',
}
const statusLabel: Record<string, string> = { active: 'Active', suspended: 'Suspended', left: 'Left' }
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="eyebrow">Members</p>
        <h1 class="mt-1 text-2xl font-bold">Member register <span v-if="total" class="text-base font-medium text-gray-500">· {{ total.toLocaleString() }}</span></h1>
        <p class="mt-1 text-gray-600">Everyone who can log in to the SACCO, with their balances. Click a name to open their account.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink :to="{ name: 'member-import' }" class="btn-secondary">Import from Excel</RouterLink>
        <button v-can="'members'" type="button" class="btn-primary" @click="editing = null">Add member</button>
      </div>
    </div>

    <div class="mt-5 flex flex-col gap-2 sm:flex-row">
      <input v-model="q" type="search" class="input sm:max-w-xs" placeholder="Search name, email, employee or account no." aria-label="Search users" />
      <select v-model="role" class="input sm:w-40" aria-label="Role">
        <option value="">All roles</option>
        <option value="member">Members</option>
        <option value="admin">Admins</option>
        <option value="super_admin">Super Admins</option>
      </select>
      <select v-model="status" class="input sm:w-40" aria-label="Status">
        <option value="">Any status</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="left">Left</option>
      </select>
    </div>

    <div v-if="error" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

    <div class="card mt-4 overflow-hidden">
      <!-- Wide screens: a table -->
      <table class="hidden w-full text-sm lg:table">
        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-4 py-3">Name</th>
            <th class="px-4 py-3 text-right">Savings</th>
            <th class="px-4 py-3 text-right">Shares</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="user in users" :key="user.id" class="group hover:bg-gray-50">
            <td class="px-4 py-2.5">
              <div class="flex items-center gap-3">
                <UserAvatar :name="user.full_name" :photo-url="user.photo_url" />
                <div class="min-w-0">
                  <RouterLink :to="{ name: 'member', params: { id: user.id } }" class="font-medium hover:text-brand hover:underline">{{ user.full_name }}</RouterLink>
                  <p class="text-xs text-gray-500">
                    <template v-if="user.employee_no">{{ user.employee_no }}</template><template v-if="user.employee_no && user.account_no"> · </template><template v-if="user.account_no">Acc <span class="font-mono">{{ user.account_no }}</span></template>
                    <template v-if="user.email"> · {{ user.email }}</template>
                    <span v-if="user.role !== 'member'" class="ml-1 rounded bg-navy-50 px-1.5 py-0.5 font-semibold text-navy-800">{{ user.role_label }}</span>
                  </p>
                </div>
              </div>
            </td>
            <template v-if="user.role === 'member'">
              <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ formatMoney(user.balances?.savings) }}</td>
              <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-gray-600">{{ formatMoney(user.balances?.shares) }}</td>
            </template>
            <td v-else colspan="2" class="px-4 py-2.5 text-right text-xs font-medium text-gray-500">Office account</td>
            <td class="whitespace-nowrap px-4 py-2.5">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusStyle[user.status]">{{ statusLabel[user.status] }}</span>
              <span v-if="user.must_change_password" class="ml-2 text-xs text-gray-500">not logged in yet</span>
            </td>
            <td class="whitespace-nowrap px-4 py-2.5 text-right">
              <span v-if="canManage(user)" class="inline-flex gap-1">
                <button v-can="'members'" type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-ink" :aria-label="`Edit ${user.full_name}`" title="Edit details" @click="editing = user"><PencilSquareIcon class="h-4 w-4" /></button>
                <button v-can="'members'" v-if="user.id !== auth.user?.id" type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-ink" :aria-label="`Give ${user.full_name} a new password`" title="Give a new password" @click="resetPassword(user)"><KeyIcon class="h-4 w-4" /></button>
              </span>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Phones: one card per user -->
      <ul class="divide-y divide-gray-100 lg:hidden">
        <li v-for="user in users" :key="user.id" class="p-4">
          <div class="flex items-start gap-3">
            <UserAvatar :name="user.full_name" :photo-url="user.photo_url" />
            <div class="min-w-0 flex-1">
              <div class="flex items-start justify-between gap-2">
                <RouterLink :to="{ name: 'member', params: { id: user.id } }" class="font-medium hover:text-brand">{{ user.full_name }}</RouterLink>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusStyle[user.status]">{{ statusLabel[user.status] }}</span>
              </div>
              <p class="truncate text-xs text-gray-500">{{ user.employee_no ?? user.email }}<template v-if="user.account_no"> · Acc <span class="font-mono">{{ user.account_no }}</span></template><template v-if="user.role !== 'member'"> · {{ user.role_label }}</template></p>
              <p v-if="user.role === 'member'" class="mt-1 text-sm tabular-nums">{{ formatMoney(user.balances?.savings) }} <span class="text-xs text-gray-500">savings · {{ formatMoney(user.balances?.shares) }} shares</span></p>
              <p v-else class="mt-1 text-xs text-gray-500">Office account</p>
              <div v-if="canManage(user)" class="mt-2 flex flex-wrap items-center gap-2">
                <button v-can="'members'" type="button" class="btn-secondary px-3 py-1.5 text-xs" @click="editing = user"><PencilSquareIcon class="h-4 w-4" />Edit</button>
                <button v-can="'members'" v-if="user.id !== auth.user?.id" type="button" class="btn-secondary px-3 py-1.5 text-xs" @click="resetPassword(user)"><KeyIcon class="h-4 w-4" />New password</button>
                <span v-if="user.must_change_password" class="text-xs text-gray-500">Not logged in yet</span>
              </div>
            </div>
          </div>
        </li>
      </ul>

      <p v-if="!loading && !users.length" class="px-4 py-10 text-center text-sm text-gray-500">No users match.</p>
      <p v-if="loading && !users.length" class="px-4 py-10 text-center text-sm text-gray-500">Loading…</p>
    </div>

    <div v-if="total > perPage" class="mt-4 flex items-center justify-between text-sm text-gray-600">
      <span>{{ (page - 1) * perPage + 1 }}–{{ Math.min(page * perPage, total) }} of {{ total }}</span>
      <div class="flex gap-2">
        <button type="button" class="btn-secondary" :disabled="page === 1" @click="page--; load()">Previous</button>
        <button type="button" class="btn-secondary" :disabled="page * perPage >= total" @click="page++; load()">Next</button>
      </div>
    </div>

    <UserForm v-if="editing !== undefined" :user="editing" @close="editing = undefined" @saved="saved" />
    <TemporaryPassword v-if="issued" :user="issued.user" :password="issued.password" @close="issued = null" />
  </div>
</template>
