<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, ApiError } from '@/api'
import { useAuth } from '@/stores/auth'
import type { Role, User, UserStatus } from '@/types'
import Modal from '@/components/Modal.vue'

/** Add a user (user is null) or edit one */
const props = defineProps<{ user: User | null }>()
const emit = defineEmits<{ close: []; saved: [user: User, temporaryPassword: string | null] }>()

const auth = useAuth()
const isSelf = props.user?.id === auth.user?.id

const form = reactive({
  full_name: props.user?.full_name ?? '',
  email: props.user?.email ?? '',
  employee_no: props.user?.employee_no ?? '',
  account_no: props.user?.account_no ?? '',
  joined_on: props.user?.joined_on ?? '',
  phone: props.user?.phone ?? '',
  national_id: props.user?.national_id ?? '',
  date_of_birth: props.user?.date_of_birth ?? '',
  gender: props.user?.gender ?? '',
  department: props.user?.department ?? '',
  position: props.user?.position ?? '',
  address: props.user?.address ?? '',
  role: (props.user?.role ?? 'member') as Role,
  status: (props.user?.status ?? 'active') as UserStatus,
})
const errors = ref<Record<string, string>>({})
const error = ref('')
const busy = ref(false)

async function save() {
  errors.value = {}
  error.value = ''
  busy.value = true
  try {
    if (props.user) {
      const res = await api.patch<{ user: User }>(`/users/${props.user.id}`, form)
      emit('saved', res.user, null)
    } else {
      const res = await api.post<{ user: User; temporary_password: string }>('/users', form)
      emit('saved', res.user, res.temporary_password)
    }
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      error.value = Object.keys(e.errors).length ? '' : e.message
    } else {
      error.value = 'Something went wrong. Please try again.'
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="user ? `Edit ${user.full_name}` : 'Add a user'" @close="emit('close')">
    <form class="space-y-4" novalidate @submit.prevent="save">
      <div v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">{{ error }}</div>

      <div>
        <label for="full_name" class="label">Full name</label>
        <input id="full_name" v-model="form.full_name" class="input" autocomplete="off" />
        <p v-if="errors.full_name" class="field-error">{{ errors.full_name }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="email" class="label">Email</label>
          <input id="email" v-model="form.email" type="email" class="input" autocomplete="off" />
          <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
        </div>
        <div>
          <label for="employee_no" class="label">Employee number</label>
          <input id="employee_no" v-model="form.employee_no" class="input" autocomplete="off" />
          <p v-if="errors.employee_no" class="field-error">{{ errors.employee_no }}</p>
        </div>
      </div>
      <p class="-mt-2 text-xs text-gray-500">They sign in with either one, so at least one is needed.</p>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="account_no" class="label">SACCO account number</label>
          <input id="account_no" v-model="form.account_no" class="input" autocomplete="off" :placeholder="user ? '' : 'Given automatically if blank'" />
          <p v-if="errors.account_no" class="field-error">{{ errors.account_no }}</p>
        </div>
        <div>
          <label for="phone" class="label">Phone</label>
          <input id="phone" v-model="form.phone" type="tel" class="input" autocomplete="off" />
        </div>
      </div>

      <details class="rounded-xl border border-line p-3" :open="!!(form.national_id || form.date_of_birth || form.department)">
        <summary class="cursor-pointer text-sm font-semibold text-gray-700">More details: ID, date of birth, department…</summary>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
          <div><label for="national_id" class="label">National ID (NIN)</label><input id="national_id" v-model="form.national_id" class="input uppercase" maxlength="30" autocomplete="off" /></div>
          <div>
            <label for="dob" class="label">Date of birth</label><input id="dob" v-model="form.date_of_birth" type="date" class="input" />
            <p v-if="errors.date_of_birth" class="field-error">{{ errors.date_of_birth }}</p>
          </div>
          <div>
            <label for="gender" class="label">Gender</label>
            <select id="gender" v-model="form.gender" class="input"><option value="">Not recorded</option><option value="female">Female</option><option value="male">Male</option></select>
          </div>
          <div><label for="department" class="label">Department</label><input id="department" v-model="form.department" class="input" maxlength="80" placeholder="e.g. Sciences, Bursar's office" /></div>
          <div><label for="position" class="label">Position</label><input id="position" v-model="form.position" class="input" maxlength="80" placeholder="e.g. Teacher, Cook" /></div>
          <div><label for="address" class="label">Home address</label><input id="address" v-model="form.address" class="input" maxlength="255" /></div>
        </div>
      </details>

      <div>
        <label for="joined_on" class="label">Member since <span class="font-normal text-gray-400">(optional)</span></label>
        <input id="joined_on" v-model="form.joined_on" type="date" :max="new Date().toLocaleDateString('en-CA')" class="input sm:w-1/2" />
        <p class="mt-1 text-xs text-gray-500">When they joined the SACCO. Loans and guaranteeing need some months of membership. Leave blank to count from their first savings entry.</p>
        <p v-if="errors.joined_on" class="field-error">{{ errors.joined_on }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="role" class="label">Role</label>
          <select id="role" v-model="form.role" class="input" :disabled="isSelf || !auth.isSuperAdmin">
            <option value="member">Member</option>
            <option v-if="auth.isSuperAdmin" value="admin">Admin</option>
            <option v-if="auth.isSuperAdmin" value="super_admin">Super Admin</option>
          </select>
          <p v-if="!auth.isSuperAdmin" class="mt-1 text-xs text-gray-500">Only a super admin can add admins.</p>
          <p v-if="form.role !== 'member'" class="mt-1 text-xs text-gray-500">An office account: it runs the SACCO and has no savings or loans. If this person also saves with the SACCO, give them a separate member account.</p>
          <p v-if="errors.role" class="field-error">{{ errors.role }}</p>
        </div>
        <div v-if="user">
          <label for="status" class="label">Status</label>
          <select id="status" v-model="form.status" class="input" :disabled="isSelf">
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="left">Left the school</option>
          </select>
        </div>
      </div>

      <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : user ? 'Save changes' : 'Add user' }}</button>
      </div>
    </form>
  </Modal>
</template>
