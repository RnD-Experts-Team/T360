<template>
  <form @submit.prevent="submit" class="space-y-6">

    <!-- Tenant dropdown for SuperAdmin -->
    <div v-if="isSuperAdmin" class="col-span-full">
      <Label>Company Name</Label>
      <select
        v-model="form.tenant_id"
        class="select-base"
      >
        <option :value="null" disabled>Select Company</option>
        <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">
          {{ tenant.name }}
        </option>
      </select>
      <InputError :message="form.errors.tenant_id" />
    </div>

    <!-- Date -->
    <div>
      <Label>Date</Label>
      <Input type="date" v-model="form.date" class="w-full" />
      <InputError :message="form.errors.date" />
    </div>

    <!-- Shared fields -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <Label for="disputed">Disputed</Label>
        <select id="disputed" v-model="form.disputed" class="select-base">
          <option value="none">None</option>
          <option value="pending">Pending</option>
          <option value="won">Won</option>
          <option value="lost">Lost</option>
        </select>
        <InputError :message="form.errors.disputed" />
      </div>

      <div>
        <Label for="carrier_controllable">Carrier Controllable</Label>
        <select id="carrier_controllable" v-model="form.carrier_controllable" class="select-base">
          <option :value="true">Yes</option>
          <option :value="false">No</option>
        </select>
        <InputError :message="form.errors.carrier_controllable" />
      </div>

      <div>
        <Label for="driver_controllable">Driver Controllable</Label>
        <select id="driver_controllable" v-model="form.driver_controllable" class="select-base">
          <option :value="true">Yes</option>
          <option :value="false">No</option>
        </select>
        <InputError :message="form.errors.driver_controllable" />
      </div>

      <div>
        <Label for="rejection_reason">Rejection Reason</Label>
        <Input
          id="rejection_reason"
          v-model="form.rejection_reason"
          placeholder="Optional reason..."
          class="w-full"
        />
        <InputError :message="form.errors.rejection_reason" />
      </div>
    </div>

    <!-- Rejection Type Selector -->
    <div>
      <Label>Rejection Type</Label>
      <div class="flex gap-2 mt-1">
        <Button
          type="button"
          v-for="t in rejectionTypes"
          :key="t.value"
          :variant="form.type === t.value ? 'default' : 'outline'"
          size="sm"
          @click="form.type = t.value"
        >
          {{ t.label }}
        </Button>
      </div>
      <InputError :message="form.errors.type" />
    </div>

    <!-- ─── Advanced Block Fields ─── -->
    <div
      v-if="form.type === 'advanced_block'"
      class="grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-md border p-4"
    >
      <p class="col-span-full text-sm font-semibold text-muted-foreground">Advanced Block Details</p>

      <div>
        <Label>Advance Block Rejection ID</Label>
        <Input v-model="form.advance_block_rejection_id" class="w-full" />
        <InputError :message="form.errors.advance_block_rejection_id" />
      </div>

      <div>
        <Label>Week Start</Label>
        <Input type="date" v-model="form.week_start" class="w-full" />
        <InputError :message="form.errors.week_start" />
      </div>

      <div>
        <Label>Week End</Label>
        <Input type="date" v-model="form.week_end" class="w-full" />
        <InputError :message="form.errors.week_end" />
      </div>

      <div>
        <Label>Impacted Blocks</Label>
        <Input type="number" min="0" v-model="form.impacted_blocks" class="w-full" />
        <InputError :message="form.errors.impacted_blocks" />
      </div>

      <div>
        <Label>Expected Blocks</Label>
        <Input type="number" min="0" v-model="form.expected_blocks" class="w-full" />
        <InputError :message="form.errors.expected_blocks" />
      </div>
    </div>

    <!-- ─── Block Fields ─── -->
    <div
      v-if="form.type === 'block'"
      class="grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-md border p-4"
    >
      <p class="col-span-full text-sm font-semibold text-muted-foreground">Block Details</p>

      <div>
        <Label>Block ID</Label>
        <Input v-model="form.block_id" class="w-full" />
        <InputError :message="form.errors.block_id" />
      </div>

      <div>
        <Label>Driver Name</Label>
        <Input v-model="form.block_driver_name" class="w-full" />
        <InputError :message="form.errors.block_driver_name" />
      </div>

      <div>
        <Label>Block Start</Label>
        <Input type="datetime-local" v-model="form.block_start" class="w-full" />
        <InputError :message="form.errors.block_start" />
      </div>

      <div>
        <Label>Block End</Label>
        <Input type="datetime-local" v-model="form.block_end" class="w-full" />
        <InputError :message="form.errors.block_end" />
      </div>

      <div>
        <Label>Rejection Date/Time</Label>
        <Input type="datetime-local" v-model="form.rejection_datetime" class="w-full" />
        <InputError :message="form.errors.rejection_datetime" />
      </div>
    </div>

    <!-- ─── Load Fields ─── -->
    <div
      v-if="form.type === 'load'"
      class="grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-md border p-4"
    >
      <p class="col-span-full text-sm font-semibold text-muted-foreground">Load Details</p>

      <div>
        <Label>Load ID</Label>
        <Input v-model="form.load_id" class="w-full" />
        <InputError :message="form.errors.load_id" />
      </div>

      <div>
        <Label>Driver Name</Label>
        <Input v-model="form.load_driver_name" class="w-full" />
        <InputError :message="form.errors.load_driver_name" />
      </div>

      <div>
        <Label>Origin Yard Arrival</Label>
        <Input type="datetime-local" v-model="form.origin_yard_arrival" class="w-full" />
        <InputError :message="form.errors.origin_yard_arrival" />
      </div>

      <div>
        <Label>Load Rejection Bucket</Label>
        <select v-model="form.load_rejection_bucket" class="select-base">
          <option value="">— Select —</option>
          <option value="rejected_after_start_time">After Start Time</option>
          <option value="rejected_0_6_hours_before_start_time">0–6 Hours Before Start</option>
          <option value="rejected_6_plus_hours_before_start_time">6+ Hours Before Start</option>
        </select>
        <InputError :message="form.errors.load_rejection_bucket" />
      </div>
    </div>

    <!-- Form Actions -->
    <div class="flex flex-col space-y-2 sm:space-y-0 sm:flex-row sm:justify-end sm:space-x-2 pt-4 border-t">
      <Button type="button" @click="emit('close')" variant="outline" class="w-full sm:w-auto">
        Cancel
      </Button>
      <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto">
        {{ form.id ? 'Update' : 'Create' }}
      </Button>
    </div>
  </form>
</template>

<script setup lang="ts">
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import Button from '@/components/ui/button/Button.vue';
import Label from '@/components/ui/label/Label.vue';
import InputError from '@/components/ui/inputError/InputError.vue';

// ─── Props ────────────────────────────────────────────────────────────────────
// NOTE: `rejection` here is the already-normalized object from Index.vue's
// `normalizedRejection` computed — it has a flat `type` field and scalar
// sub-relation fields, NOT raw hasMany arrays.
const props = defineProps({
  rejection:    { type: Object,  default: null  },
  tenants:      { type: Array,   default: () => [] },
  isSuperAdmin: { type: Boolean, default: false },
  tenantSlug:   { type: String,  default: null  },
});

const emit = defineEmits(['close', 'success']);

const rejectionTypes = [
  { value: 'advanced_block', label: 'Advanced Block' },
  { value: 'block',          label: 'Block'          },
  { value: 'load',           label: 'Load'           },
];

// ─── Helpers ──────────────────────────────────────────────────────────────────
// The normalized rejection already has a `type` field set by Index.vue.
// We just read it directly — no relation sniffing needed here.
function resolveType(r: any): string {
  if (!r) return 'block';
  // normalizedRejection always sets `type` explicitly
  if (r.type && ['advanced_block', 'block', 'load'].includes(r.type)) return r.type;
  return 'block';
}

// datetime-local inputs require "YYYY-MM-DDTHH:mm" format.
// The backend returns "2026-02-14 00:00:00" — convert it.
function toDatetimeLocal(val: string | null): string {
  if (!val) return '';
  // Already in correct format
  if (val.includes('T')) return val.slice(0, 16);
  // "YYYY-MM-DD HH:mm:ss" → "YYYY-MM-DDTHH:mm"
  return val.replace(' ', 'T').slice(0, 16);
}

// date inputs require "YYYY-MM-DD" — strip any time portion
function toDateOnly(val: string | null): string {
  if (!val) return '';
  return String(val).split('T')[0].split(' ')[0];
}

// Boolean coercion — DB returns 0/1 integers, selects bind to true/false
function toBool(val: any): boolean {
  if (val === true || val === 1 || val === '1') return true;
  return false;
}

// ─── Build initial form state from a rejection (or blank) ─────────────────────
function buildFormData(r: any) {
  const type = resolveType(r);
  return {
    id:        r?.id        ?? null,
    tenant_id: r?.tenant_id ?? null,

    // Shared
    date:                 toDateOnly(r?.date)           ?? '',
    disputed:             r?.disputed                   ?? 'none',
    carrier_controllable: toBool(r?.carrier_controllable),
    driver_controllable:  toBool(r?.driver_controllable),
    rejection_reason:     r?.rejection_reason            ?? '',

    // Type
    type,

    // Advanced Block (populated when type === 'advanced_block')
    advance_block_rejection_id: r?.advance_block_rejection_id ?? '',
    week_start:      toDateOnly(r?.week_start)                ?? '',
    week_end:        toDateOnly(r?.week_end)                  ?? '',
    impacted_blocks: r?.impacted_blocks                       ?? '',
    expected_blocks: r?.expected_blocks                       ?? '',

    // Block (populated when type === 'block')
    block_id:           r?.block_id                            ?? '',
    block_driver_name:  r?.block_driver_name                   ?? '',
    block_start:        toDatetimeLocal(r?.block_start)        ?? '',
    block_end:          toDatetimeLocal(r?.block_end)          ?? '',
    rejection_datetime: toDatetimeLocal(r?.rejection_datetime) ?? '',

    // Load (populated when type === 'load')
    load_id:               r?.load_id                             ?? '',
    load_driver_name:      r?.load_driver_name                    ?? '',
    origin_yard_arrival:   toDatetimeLocal(r?.origin_yard_arrival) ?? '',
    load_rejection_bucket: r?.load_rejection_bucket               ?? '',
  };
}

// ─── Form ─────────────────────────────────────────────────────────────────────
const form = useForm(buildFormData(props.rejection));

// ─── Watch: re-populate when the prop changes (modal re-used for add/edit) ────
watch(
  () => props.rejection,
  (r) => {
    const data = buildFormData(r);
    // Assign all keys to the existing form instance so Inertia tracks them
    Object.keys(data).forEach((key) => {
      (form as any)[key] = (data as any)[key];
    });
    form.clearErrors();
  },
  { immediate: true }
);

// ─── Submit ───────────────────────────────────────────────────────────────────
function submit() {
  const isEdit = !!form.id;

  const routeName = props.isSuperAdmin
    ? (isEdit ? 'acceptance.update.admin' : 'acceptance.store.admin')
    : (isEdit ? 'acceptance.update'       : 'acceptance.store');

  const routeParams = props.isSuperAdmin
    ? (isEdit ? { rejection: form.id } : {})
    : (isEdit
        ? { tenantSlug: props.tenantSlug, rejection: form.id }
        : { tenantSlug: props.tenantSlug });

  const method = isEdit ? 'put' : 'post';

  form[method](route(routeName, routeParams), {
    preserveScroll: true,
    onSuccess: () => {
      emit('success');
      emit('close');
    },
  });
}
</script>

<style scoped>
.select-base {
  @apply flex h-10 w-full items-center rounded-md border border-input bg-background
         px-3 py-2 text-sm ring-offset-background appearance-none
         focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2
         disabled:cursor-not-allowed disabled:opacity-50;
}
</style>
