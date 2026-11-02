<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { User } from '@/types'
import { formatMoney } from '@/money'
import UserForm from './UserForm.vue'
import TemporaryPassword from './TemporaryPassword.vue'

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
  if (!confirm(`Give ${user.full_name} a new temporary password? Their current password will stop working.`)) return
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
        <h1 class="text-xl font-semibold">Members &amp; users</h1>
        <p class="mt-1 text-sm text-gray-600">Everyone who can sign in to the SACCO.</p>
      </div>
      <button type="button" class="btn-primary" @click="editing = null">Add user</button>
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
      <table class="hidden w-full text-sm md:table">
        <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
          <tr>
            <th class="px-4 py-3">Name</th>
            <th class="px-4 py-3">Signs in with</th>
            <th class="px-4 py-3">Account no.</th>
            <th class="px-4 py-3 text-right">Savings</th>
            <th class="px-4 py-3 text-right">Shares</th>
            <th class="px-4 py-3">Role</th>
            <th class="px-4 py-3">Status</th>
            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="user in users" :key="user.id" class="hover:bg-gray-50">
            <td class="px-4 py-3">
              <RouterLink :to="{ name: 'member', params: { id: user.id } }" class="font-medium hover:text-brand hover:underline">{{ user.full_name }}</RouterLink>
              <span v-if="user.must_change_password" class="block text-xs text-amber-700">hasn't set a password</span>
            </td>
            <td class="px-4 py-3 text-gray-600">
              {{ user.email }}<br v-if="user.email && user.employee_no" />{{ user.employee_no }}
            </td>
            <td class="px-4 py-3 font-mono text-gray-700">{{ user.account_no }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(user.balances?.savings) }}</td>
            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(user.balances?.shares) }}</td>
            <td class="px-4 py-3">{{ user.role_label }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusStyle[user.status]">{{ statusLabel[user.status] }}</span>
            </td>
            <td class="whitespace-nowrap px-4 py-3 text-right">
              <template v-if="canManage(user)">
                <button type="button" class="btn-ghost px-2 py-1" @click="editing = user">Edit</button>
                <button v-if="user.id !== auth.user?.id" type="button" class="btn-ghost px-2 py-1" @click="resetPassword(user)">Reset password</button>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Phones: one card per user -->
      <ul class="divide-y divide-gray-100 md:hidden">
        <li v-for="user in users" :key="user.id" class="p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <RouterLink :to="{ name: 'member', params: { id: user.id } }" class="font-medium hover:text-brand">{{ user.full_name }}</RouterLink>
              <p class="truncate text-sm text-gray-600">{{ user.email ?? user.employee_no }}</p>
              <p class="mt-1 text-xs text-gray-500">{{ user.role_label }} · <span class="font-mono">{{ user.account_no }}</span></p>
              <p class="mt-1 text-xs tabular-nums text-gray-600">Savings {{ formatMoney(user.balances?.savings) }} · Shares {{ formatMoney(user.balances?.shares) }}</p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusStyle[user.status]">{{ statusLabel[user.status] }}</span>
          </div>
          <div v-if="canManage(user)" class="mt-2 flex gap-2">
            <button type="button" class="btn-secondary px-3 py-1.5" @click="editing = user">Edit</button>
            <button v-if="user.id !== auth.user?.id" type="button" class="btn-secondary px-3 py-1.5" @click="resetPassword(user)">Reset password</button>
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
