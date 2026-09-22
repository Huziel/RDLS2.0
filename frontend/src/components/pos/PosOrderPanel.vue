<script setup>
import { computed, nextTick } from 'vue'
import { formatProductPrice } from '../../utils/products.js'
import { posOrderTotal } from '../../utils/pos.js'

const props = defineProps({
  order: { type: Object, default: null },
  extra: { type: Number, default: 0 },
  discount: { type: Number, default: 0 },
  paymentMethod: { type: String, default: 'efectivo' },
  loyalty: { type: Object, default: null },
  loyaltyPoints: { type: Number, default: 0 },
  busy: { type: Boolean, default: false },
})

const emit = defineEmits([
  'update:extra',
  'update:paymentMethod',
  'update:loyaltyPoints',
  'quantity',
  'remove',
  'save',
  'pay',
  'delete',
  'check-loyalty',
])

const total = computed(() => posOrderTotal(props.order, props.extra, props.discount))

async function updateLoyalty(event) {
  emit('update:loyaltyPoints', Number(event.target.value || 0))
  await nextTick()
  event.target.value = props.loyaltyPoints
}
</script>

<template>
  <aside class="pos-order panel">
    <div v-if="!order" class="pos-order-empty">
      <strong>Sin orden seleccionada</strong>
      <span>Crea o selecciona una orden para comenzar.</span>
    </div>

    <template v-else>
      <header class="pos-order-heading">
        <div><span>Orden</span><strong>{{ order.noOrder }}</strong></div>
        <span class="status-badge" :class="order.estado === 0 ? 'inactive' : 'active'">
          {{ order.estado === 0 ? 'Pendiente' : 'Guardada' }}
        </span>
      </header>
      <p class="pos-customer"><strong>{{ order.nombre }}</strong><span>{{ order.telefono || 'Sin teléfono' }}</span></p>

      <div class="pos-lines">
        <article v-for="line in order.details" :key="line.id" class="pos-line">
          <div><strong>{{ line.nameProd }}</strong><small>{{ formatProductPrice(line.precioBruto) }} c/u</small></div>
          <div class="pos-quantity">
            <button type="button" :disabled="busy || order.estado !== 0 || line.cantidad <= 1" :aria-label="`Reducir ${line.nameProd}`" @click="emit('quantity', line, -1)">-</button>
            <span>{{ line.cantidad }}</span>
            <button type="button" :disabled="busy || order.estado !== 0" :aria-label="`Aumentar ${line.nameProd}`" @click="emit('quantity', line, 1)">+</button>
          </div>
          <b>{{ formatProductPrice(line.precioNeto) }}</b>
          <button v-if="order.estado === 0" class="pos-remove" type="button" :disabled="busy" :aria-label="`Eliminar ${line.nameProd}`" @click="emit('remove', line)">Quitar</button>
        </article>
        <p v-if="!order.details.length" class="empty-state">Agrega productos a esta orden.</p>
      </div>

      <div class="pos-loyalty">
        <button class="button button-secondary button-small" type="button" :disabled="busy || !order.telefono" @click="emit('check-loyalty')">Consultar puntos</button>
        <template v-if="loyalty?.registered">
          <span><strong>{{ loyalty.client_name }}</strong> tiene {{ loyalty.points }} puntos.</span>
          <label v-if="order.estado === 1 && loyalty.can_redeem">
            Puntos a canjear
            <input
              type="number"
              :min="loyalty.minimum_to_redeem"
              :max="loyalty.points"
              :step="Math.max(1, Number(loyalty.pesos_per_point || 1))"
              :value="loyaltyPoints"
              :disabled="busy"
              @input="updateLoyalty"
            />
          </label>
        </template>
        <span v-else-if="loyalty && !loyalty.registered">Cliente no registrado en lealtad.</span>
      </div>

      <label v-if="order.estado === 0" class="pos-extra">
        Cargo extra
        <input type="number" min="0" step="0.01" :value="extra" :disabled="busy" @input="emit('update:extra', Number($event.target.value || 0))" />
      </label>

      <dl class="pos-totals">
        <div v-if="discount > 0"><dt>Descuento estimado</dt><dd>-{{ formatProductPrice(discount) }}</dd></div>
        <div><dt>Total</dt><dd>{{ formatProductPrice(total) }}</dd></div>
      </dl>

      <div v-if="order.estado === 0" class="pos-order-actions">
        <button class="button button-primary" type="button" :disabled="busy || !order.details.length" @click="emit('save')">Guardar orden</button>
        <button class="button button-danger" type="button" :disabled="busy" @click="emit('delete')">Eliminar</button>
      </div>
      <div v-else class="pos-order-actions">
        <label>
          Método de pago
          <select :value="paymentMethod" :disabled="busy" @change="emit('update:paymentMethod', $event.target.value)">
            <option value="efectivo">Efectivo</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="transferencia">Transferencia</option>
          </select>
        </label>
        <button class="button button-primary" type="button" :disabled="busy" @click="emit('pay')">{{ busy ? 'Procesando...' : 'Cobrar' }}</button>
      </div>
    </template>
  </aside>
</template>
