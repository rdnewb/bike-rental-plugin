exports.fillLocation = async page => {
 for(const [key,value] of Object.entries({name:'Fixture Guest',address_1:'123 Example Street',city:'Example City',postcode:'34205'})) await page.locator('[name=location_'+key+']').fill(value);
 await page.locator('[name=location_state]').selectOption('FL');
};
